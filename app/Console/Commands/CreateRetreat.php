<?php

namespace App\Console\Commands;

use App\Models\Retreat;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateRetreat extends Command
{
    protected $signature = 'retreat:create
                            {name : The name of the retreat}
                            {--code= : Custom retreat code (auto-generated if not provided)}
                            {--destination= : Destination name}
                            {--lat= : Destination latitude}
                            {--lng= : Destination longitude}
                            {--starts= : Start date/time (default: now)}
                            {--ends= : End date/time (default: 3 days from now)}';

    protected $description = 'Create a new retreat for location tracking';

    public function handle(): int
    {
        $name = $this->argument('name');

        $code = $this->option('code')
            ? strtoupper($this->option('code'))
            : strtoupper(Str::random(8));

        $startsAt = $this->option('starts')
            ? new \DateTime($this->option('starts'))
            : now();

        $endsAt = $this->option('ends')
            ? new \DateTime($this->option('ends'))
            : now()->addDays(3);

        if (Retreat::where('code', $code)->exists()) {
            $this->error("A retreat with code '{$code}' already exists.");
            return Command::FAILURE;
        }

        $retreat = Retreat::create([
            'name' => $name,
            'code' => $code,
            'destination_name' => $this->option('destination'),
            'destination_lat' => $this->option('lat'),
            'destination_lng' => $this->option('lng'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => true,
        ]);

        $this->info('Retreat created successfully!');
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $retreat->id],
                ['Name', $retreat->name],
                ['Code', $retreat->code],
                ['Destination', $retreat->destination_name ?? 'Not set'],
                ['Starts', $retreat->starts_at->format('Y-m-d H:i')],
                ['Ends', $retreat->ends_at->format('Y-m-d H:i')],
            ]
        );

        $this->newLine();
        $this->info("Share this code with participants: {$retreat->code}");

        return Command::SUCCESS;
    }
}

