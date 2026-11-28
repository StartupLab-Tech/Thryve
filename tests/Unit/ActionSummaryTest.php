<?php

namespace Tests\Unit;

use App\Plugins\ActionSummary;
use PHPUnit\Framework\TestCase;

/** The person reads plain words on the approval card and in the chat line, never tool names or an app's raw JSON. */
class ActionSummaryTest extends TestCase
{
    public function test_a_proposed_change_reads_as_an_action_with_labelled_details(): void
    {
        $this->assertSame('Send draft: subject "AI Subscription Renewal", to "ann@example.com"',
            ActionSummary::for('google_gmail_send_draft', ['subject' => 'AI Subscription Renewal', 'to' => 'ann@example.com', 'ticket' => 'secret']));
        $this->assertSame('Create issue in octo/sandbox: title "Bug"', ActionSummary::for('issue_write', ['method' => 'create', 'owner' => 'octo', 'repo' => 'sandbox', 'title' => 'Bug']));
    }

    public function test_an_apps_json_reply_becomes_what_happened_and_a_link(): void
    {
        $this->assertSame('Done. "license.txt" Open it: https://drive.google.com/file/d/1v1/view',
            ActionSummary::outcome('{"id":"1v1","title":"license.txt","mimeType":"text/plain","webViewLink":"https://drive.google.com/file/d/1v1/view"}'));
        $this->assertSame('Sent.', ActionSummary::outcome('{"sent":true,"id":"1a2","threadId":"1a2","labels":["SENT"]}'));
        $this->assertSame('Done.', ActionSummary::outcome('{"id":"r88","viewUrl":"javascript:alert(1)"}'));
        $this->assertSame('Issue created.', ActionSummary::outcome("Issue\ncreated."));
    }
}
