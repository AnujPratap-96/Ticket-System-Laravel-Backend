<?php

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Seeder;

/**
 * Starter help-center content. Safe to re-run (matched by slug). Replace with your own articles.
 */
class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $articles = [
            ['how-to-create-a-ticket', 'How to create a support ticket', 'Open a request with the details our team needs.',
                "Sign in, open Create ticket and choose the department that matches your problem.\n\nGive the ticket a short subject, then describe what happened, what you expected, and any error message you saw.\n\nAttach screenshots or log files if you can. Drag them in, browse for them, or paste a screenshot with Ctrl+V."],
            ['response-times-and-priorities', 'Response times and ticket priorities', 'What Low, Medium, High and Urgent mean for response times.',
                "Every ticket has a priority. Higher priorities get shorter response and resolution targets.\n\nUrgent is for outages that stop you working. High is for serious problems with a workaround. Medium and Low are for questions and minor issues.\n\nTargets are counted in the support team's business hours, so a ticket sent on Friday evening is measured from Monday morning."],
            ['reset-your-password', 'Reset your password', 'Recover access if you forgot your password.',
                "On the sign-in page choose Forgot password and enter your email.\n\nWe send a 6-digit code that is valid for 10 minutes. Enter it with your new password.\n\nFor your security, resetting your password signs you out of every device."],
            ['attaching-files', 'Attaching screenshots and files', 'Which files you can attach and how large they can be.',
                "You can attach up to 5 files per message: JPG, PNG, GIF, WEBP, PDF, TXT, LOG and ZIP, up to 10 MB each.\n\nFiles are private. Only you and our support team can open them.\n\nTip: paste a screenshot straight into the message box with Ctrl+V."],
            ['reopening-a-ticket', 'Reopening or closing a ticket', 'Not solved yet? Reply and we pick it back up.',
                "When we mark a ticket as resolved you can still reply. Your reply reopens it and moves it back to the team.\n\nIf you no longer need help, choose Close ticket. Closed tickets can no longer be replied to, so create a new ticket if the problem returns.\n\nAfter a ticket is resolved you can also rate your support experience."],
            ['delete-my-account-and-data', 'Download or delete your data', 'Your privacy controls.',
                "Open My account to download a copy of your profile, tickets and replies as a file.\n\nYou can also delete your account there. Your name, email and message text are permanently removed. Ticket records are kept in anonymised form for our records."],
        ];

        foreach ($articles as [$slug, $title, $summary, $body]) {
            Article::firstOrCreate(['slug' => $slug], ['title' => $title, 'summary' => $summary, 'body' => $body, 'is_published' => true]);
        }
    }
}
