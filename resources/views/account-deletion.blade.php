<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Deletion - Calvary Caravan</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            line-height: 1.6;
            color: #262626;
            background: #FAFAFA;
            padding: 20px;
        }
        .container {
            max-width: 820px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        h1 {
            color: #8B1D1D;
            font-size: 28px;
            margin-bottom: 8px;
        }
        .sub {
            color: #737373;
            font-size: 14px;
            margin-bottom: 24px;
        }
        h2 {
            color: #262626;
            font-size: 20px;
            margin-top: 28px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #E5E5E5;
        }
        p, li { color: #525252; margin-bottom: 12px; }
        ul, ol { margin-left: 24px; margin-bottom: 16px; }
        .card {
            background: #F5F5F5;
            padding: 18px;
            border-radius: 10px;
            margin-top: 14px;
        }
        .note {
            background: #FEF3F3;
            border-left: 4px solid #8B1D1D;
            padding: 14px;
            border-radius: 0 8px 8px 0;
            margin: 14px 0;
        }
        a { color: #1E4D8C; text-decoration: none; font-weight: 600; }
        a:hover { text-decoration: underline; }
        code {
            background: #FAFAFA;
            border: 1px solid #E5E5E5;
            padding: 2px 6px;
            border-radius: 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
            font-size: 0.95em;
        }
        .footer {
            text-align: center;
            margin-top: 34px;
            padding-top: 18px;
            border-top: 1px solid #E5E5E5;
            color: #737373;
            font-size: 14px;
            font-style: italic;
        }
        @media (max-width: 600px) {
            body { padding: 12px; }
            .container { padding: 24px; }
            h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Delete Your Calvary Caravan Account Data</h1>
        <p class="sub">Last updated: February 12, 2026</p>

        <p>
            Calvary Caravan supports direct in-app account deletion.
            This removes your retreat participant profile and associated retreat data.
        </p>

        <h2>Delete in the app (recommended)</h2>
        <ol>
            <li>Open <strong>Calvary Caravan</strong>.</li>
            <li>Join your retreat (if not already joined).</li>
            <li>Go to <strong>Profile</strong> tab.</li>
            <li>Select <strong>Delete account &amp; data</strong>.</li>
            <li>Confirm deletion to permanently remove your retreat participant record.</li>
        </ol>

        <div class="card">
            <p><strong>Deletion endpoint (technical):</strong> <code>DELETE /api/v1/retreat/account</code> with your session token and <code>confirm_delete=true</code>.</p>
            <p><strong>What is deleted immediately:</strong></p>
            <ul>
                <li>Participant profile (name, phone identity, vehicle details, avatar)</li>
                <li>Location history associated with your participant record</li>
                <li>Messages associated with your participant record</li>
                <li>Active session token for that account identity</li>
            </ul>
        </div>

        <h2>Need help deleting?</h2>
        <p>
            If you cannot access the app, email
            <a href="mailto:support@calvarybaptist.church">support@calvarybaptist.church</a>
            with your phone number and retreat code.
            We will process your deletion request manually.
        </p>

        <div class="note">
            <p>
                <strong>Important:</strong> Without OTP verification (current app behavior), phone-number identity is best-effort.
                If your number is reused on another device, the latest join session takes ownership of that retreat profile.
            </p>
        </div>

        <p>
            Related pages:
            <a href="/privacy">Privacy Policy</a> ·
            <a href="/support">Support</a>
        </p>

        <p class="footer">This page applies specifically to the Calvary Caravan app account-deletion flow.</p>
    </div>
</body>
</html>
