import { schema, table, t } from 'spacetimedb/server';

const MAX_REASONABLE_ACCURACY_METERS = 5000;
const MAX_REASONABLE_SPEED_MPS = 120;
const MAX_REASONABLE_ALTITUDE_METERS = 12000;
const MAX_LOCATION_AGE_MS = 6n * 60n * 60n * 1000n; // 6 hours

const LatestLocationRow = t.row({
  participant_id: t.u64().primaryKey(),
  retreat_id: t.u64().index('btree'),
  latitude: t.f64(),
  longitude: t.f64(),
  accuracy: t.f64(),
  speed: t.f64(),
  heading: t.f64(),
  altitude: t.f64(),
  recorded_at_ms: t.i64(),
  updated_at_ms: t.i64(),
});

const UpsertLocationResponse = t.object('UpsertLocationResponse', {
  recorded: t.bool(),
  next_update_in: t.u32(),
});

const LatestLocationResponse = t.object('LatestLocationResponse', {
  participant_id: t.u64(),
  retreat_id: t.u64(),
  latitude: t.f64(),
  longitude: t.f64(),
  accuracy: t.f64(),
  speed: t.f64(),
  heading: t.f64(),
  altitude: t.f64(),
  recorded_at_ms: t.i64(),
  updated_at_ms: t.i64(),
});

const clamp = (value: number, min: number, max: number): number => {
  if (Number.isNaN(value)) return min;
  if (value < min) return min;
  if (value > max) return max;
  return value;
};

const isFiniteNumber = (value: number): boolean => Number.isFinite(value);

const normalizeHeading = (value: number): number => {
  if (!isFiniteNumber(value)) return 0;

  const normalized = value % 360;
  return normalized < 0 ? normalized + 360 : normalized;
};

const toBigInt = (value: bigint | number): bigint => {
  if (typeof value === 'bigint') return value;
  return BigInt(Math.trunc(value));
};

export const spacetimedb = schema(
  table({ name: 'latest_location', public: true }, LatestLocationRow)
);

spacetimedb.procedure(
  'upsert_location',
  {
    participant_id: t.u64(),
    retreat_id: t.u64(),
    latitude: t.f64(),
    longitude: t.f64(),
    accuracy: t.f64(),
    speed: t.f64(),
    heading: t.f64(),
    altitude: t.f64(),
    recorded_at_ms: t.i64(),
  },
  UpsertLocationResponse,
  (ctx, args) => {
    const participantId = toBigInt(args.participant_id);
    const retreatId = toBigInt(args.retreat_id);

    if (participantId <= 0n || retreatId <= 0n) {
      return {
        recorded: false,
        next_update_in: 60,
      };
    }

    if (!isFiniteNumber(args.latitude) || !isFiniteNumber(args.longitude)) {
      return {
        recorded: false,
        next_update_in: 60,
      };
    }

    const latitude = clamp(args.latitude, -90, 90);
    const longitude = clamp(args.longitude, -180, 180);
    const accuracy = clamp(Math.abs(args.accuracy), 0, MAX_REASONABLE_ACCURACY_METERS);
    const speed = clamp(Math.abs(args.speed), 0, MAX_REASONABLE_SPEED_MPS);
    const heading = normalizeHeading(args.heading);
    const altitude = clamp(args.altitude, -1000, MAX_REASONABLE_ALTITUDE_METERS);

    const nowMs = BigInt(Date.now());
    const recordedAtMsRaw = toBigInt(args.recorded_at_ms);
    const recordedAtMs = recordedAtMsRaw > 0n ? recordedAtMsRaw : nowMs;

    let recorded = true;
    let nextUpdateIn = 30;

    ctx.withTx(tx => {
      for (const row of tx.db.latestLocation.iter()) {
        if (row.participant_id !== participantId) {
          continue;
        }

        if (row.retreat_id !== retreatId) {
          // participant IDs should never cross retreat boundaries.
          recorded = false;
          nextUpdateIn = 120;
          return;
        }

        if (recordedAtMs <= row.recorded_at_ms) {
          // stale/duplicate point; keep current latest point.
          recorded = false;
          nextUpdateIn = 12;
          return;
        }

        tx.db.latestLocation.delete(row);
        break;
      }

      if (!recorded) {
        return;
      }

      tx.db.latestLocation.insert({
        participant_id: participantId,
        retreat_id: retreatId,
        latitude,
        longitude,
        accuracy,
        speed,
        heading,
        altitude,
        recorded_at_ms: recordedAtMs,
        updated_at_ms: nowMs,
      });
    });

    return {
      recorded,
      next_update_in: nextUpdateIn,
    };
  }
);

spacetimedb.procedure(
  'list_latest_locations_for_retreat',
  { retreat_id: t.u64() },
  t.array(LatestLocationResponse),
  (ctx, { retreat_id }) => {
    const retreatId = toBigInt(retreat_id);

    const rows: Array<{
      participant_id: bigint;
      retreat_id: bigint;
      latitude: number;
      longitude: number;
      accuracy: number;
      speed: number;
      heading: number;
      altitude: number;
      recorded_at_ms: bigint;
      updated_at_ms: bigint;
    }> = [];

    const nowMs = BigInt(Date.now());

    ctx.withTx(tx => {
      for (const row of tx.db.latestLocation.iter()) {
        if (row.retreat_id !== retreatId) {
          continue;
        }

        const ageMs = nowMs - row.updated_at_ms;
        if (ageMs > MAX_LOCATION_AGE_MS) {
          // hide stale points from realtime readers
          continue;
        }

        rows.push({
          participant_id: row.participant_id,
          retreat_id: row.retreat_id,
          latitude: row.latitude,
          longitude: row.longitude,
          accuracy: row.accuracy,
          speed: row.speed,
          heading: row.heading,
          altitude: row.altitude,
          recorded_at_ms: row.recorded_at_ms,
          updated_at_ms: row.updated_at_ms,
        });
      }
    });

    rows.sort((a, b) => {
      if (a.updated_at_ms === b.updated_at_ms) return 0;
      return a.updated_at_ms > b.updated_at_ms ? -1 : 1;
    });

    return rows;
  }
);
