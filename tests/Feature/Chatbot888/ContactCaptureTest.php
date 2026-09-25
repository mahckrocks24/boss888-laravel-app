<?php

namespace Tests\Feature\Chatbot888;

use App\Engines\Chatbot\Services\ChatbotResponseService;
use App\Engines\Chatbot\Services\ChatbotSessionStateService;
use Tests\TestCase;

/**
 * CB-CONTACT-1 (Owner 2026-09-25, Boss Mac Pet Shop widget): "it should say it does not look like a valid phone number".
 * The lead flow asked for "the best number or email" but required an email, so a phone never satisfied it and an
 * unusable answer got the same canned question again, forever.
 */
class ContactCaptureTest extends TestCase
{
    public function test_a_phone_number_alone_completes_the_lead_flow(): void
    {
        $fsm = app(ChatbotSessionStateService::class);
        $t = $fsm->transition(ChatbotSessionStateService::STATE_COLLECTING_LEAD, 'unknown', ['name' => 'Mark', 'phone' => '4572527']);
        $this->assertSame(ChatbotSessionStateService::ACTION_FINALISE_LEAD, $t['action'], 'a phone is a contact');
        $t = $fsm->transition(ChatbotSessionStateService::STATE_COLLECTING_LEAD, 'unknown', ['name' => 'Mark', 'email' => 'mark@example.test']);
        $this->assertSame(ChatbotSessionStateService::ACTION_FINALISE_LEAD, $t['action'], 'an email is a contact');
        $t = $fsm->transition(ChatbotSessionStateService::STATE_COLLECTING_LEAD, 'unknown', ['name' => 'Mark']);
        $this->assertSame(ChatbotSessionStateService::ACTION_ASK_FIELD, $t['action']);
        $this->assertSame('contact', $t['next_field'], 'with neither, the bot asks for a contact');
        $this->assertSame(['name', 'contact', 'date'], $fsm->requiredFieldsForFlow('booking'));
        $this->assertStringContainsString('number or email', $fsm->promptForField('contact', 'lead', []));
    }

    public function test_contact_kind_tells_a_phone_from_an_email_from_noise(): void
    {
        $this->assertSame('phone', ChatbotResponseService::contactKind('4572527'));
        $this->assertSame('phone', ChatbotResponseService::contactKind('+971 50 000 0001'));
        $this->assertSame('email', ChatbotResponseService::contactKind('Mark@Example.test'));
        $this->assertNull(ChatbotResponseService::contactKind('45'), 'two digits are not a phone number');
        $this->assertNull(ChatbotResponseService::contactKind('call me later'));
        $this->assertNull(ChatbotResponseService::contactKind('2026-09-25'), 'a date is not a phone');
        $this->assertNull(ChatbotResponseService::contactKind(null));
    }

    public function test_an_unusable_answer_gets_a_specific_reply_not_the_same_question(): void
    {
        $svc = app(ChatbotResponseService::class);
        $ask = new \ReflectionMethod($svc, 'actionAskField'); $ask->setAccessible(true);
        $transition = ['state' => ChatbotSessionStateService::STATE_COLLECTING_LEAD, 'action' => ChatbotSessionStateService::ACTION_ASK_FIELD, 'flow' => 'lead', 'next_field' => 'contact'];
        $ctx = ['session_id' => 0, 'kb_hits_count' => 0, 'business_name' => 'Boss Mac Pet Shop'];

        // first time asked: the plain question
        [$payload] = $ask->invoke($svc, $transition, $ctx, ['intent' => 'lead_capture', 'source' => 'rule'], ['name' => 'Mark'], 'I want a quote', 'name', 0);
        $this->assertStringContainsString('best number or email', $payload['data']['message']);

        // asked for a contact, answered "45": the reply says what was wrong (runtime is not configured under tests → the deterministic wording)
        [$payload] = $ask->invoke($svc, $transition, $ctx, ['intent' => 'lead_capture', 'source' => 'rule'], ['name' => 'Mark'], '45', 'contact', 0);
        $msg = $payload['data']['message'];
        $this->assertStringContainsString("doesn't look like a valid phone number or email", $msg);
        $this->assertStringNotContainsString('best number or email so the team', $msg, 'never the same canned question again');
        $this->assertSame(['email', 'phone'], $payload['data']['capture_fields']);

        // the fallback for the other fields is specific too
        $retry = new \ReflectionMethod($svc, 'retryFieldMessage'); $retry->setAccessible(true);
        $this->assertStringContainsString('valid phone number', $retry->invoke($svc, 'phone', 'callback', '45', 1, $ctx));
        $this->assertStringContainsString("didn't catch a name", $retry->invoke($svc, 'name', 'lead', '???', 1, $ctx));
        $this->assertStringContainsString('share a contact whenever', $retry->invoke($svc, 'contact', 'lead', '45', 2, $ctx), 'a second miss adds the no-pressure line');
    }
}
