<?php

declare(strict_types=1);

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Domain\UnsubscribeForm;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnsubscribeFormTest extends TestCase
{
    private const FLOW = '06b3cdcb-acd5-4987-8d4f-47673cd418ab';

    private function flow(): array
    {
        return ['id' => self::FLOW, 'name' => 'Dev unsubscribe', 'playbook' => 'unsubscribe', 'setup' => ['groupid' => '758666']];
    }

    private function config(): array
    {
        return ['group_id' => 758666, 'unsubscribe_form_id' => self::FLOW, 'sender_email' => 'club@example.org', 'release_verified' => true];
    }

    public function testIdentifiersPreserveLegacyNumbersAndFlowUuid(): void
    {
        self::assertSame('432342', UnsubscribeForm::identifier(432342));
        self::assertSame(self::FLOW, UnsubscribeForm::identifier(self::FLOW));
        self::assertSame('', UnsubscribeForm::identifier(0));
        self::assertSame('', UnsubscribeForm::identifier(''));
    }

    public static function invalidIds(): array
    {
        return [['../flow'], ['758666x'], ['-1'], [1.5], [['id' => self::FLOW]], ['{UNSUBSCRIBE}']];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdentifierCannotBecomeAnApiPath(mixed $id): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('COM_INTERCOM_INVALID_UNSUBSCRIBE');
        UnsubscribeForm::identifier($id);
    }

    public function testSelectorUsesFlowApiAndOnlyUnsubscribeFormsForThisList(): void
    {
        $seen = [];
        $valid = $this->flow();
        $gateway = new CleverReachGateway(fn () => 'fixture', $this->config(), function ($method, $path) use (&$seen, $valid) {
            $seen[] = [$method, $path];
            return [$valid, array_replace($valid, ['setup' => ['groupid' => '999']]),
                array_replace($valid, ['playbook' => 'subscribe']), ['id' => self::FLOW, 'setup' => 'invalid'], null];
        });
        self::assertSame([$valid], $gateway->unsubscribeForms(758666));
        self::assertSame([['GET', '/flow/flow?playbook=unsubscribe&order=name&dir=asc']], $seen);
    }

    public static function invalidFlows(): array
    {
        return [
            [['id' => self::FLOW, 'playbook' => 'unsubscribe', 'setup' => ['groupid' => '999']]],
            [['id' => self::FLOW, 'playbook' => 'subscribe', 'setup' => ['groupid' => '758666']]],
            [['id' => self::FLOW, 'playbook' => 'unsubscribe', 'setup' => ['groupid' => '758666x']]],
            [['id' => self::FLOW, 'playbook' => 'unsubscribe', 'setup' => 'invalid']],
            [['id' => '11111111-1111-1111-1111-111111111111', 'playbook' => 'unsubscribe', 'setup' => ['groupid' => '758666']]],
            [false],
        ];
    }

    #[DataProvider('invalidFlows')]
    public function testWrongListOrFormTypeBlocksPreparationBeforeAnyMutation(mixed $flow): void
    {
        $gateway = new CleverReachGateway(fn () => 'fixture', $this->config(), function ($method) use ($flow) {
            self::assertSame('GET', $method);
            return $flow;
        });
        $this->expectExceptionMessage('COM_INTERCOM_INVALID_UNSUBSCRIBE');
        $gateway->prepare([], 123, 0);
    }

    public function testCreationAndUpdatePassUuidAndPreserveBothUnsubscribeDirectives(): void
    {
        $seen = [];
        $gateway = new CleverReachGateway(fn () => 'fixture', $this->config(), function ($method, $path, $body) use (&$seen) {
            $seen[] = [$method, $path, $body];
            if (str_starts_with($path, '/flow/')) {
                return $this->flow();
            }
            if ($method === 'GET') {
                return ['unsubscribe_form_id' => self::FLOW];
            }
            return ['id' => 456];
        });
        $message = Message::validate(['type' => 'club', 'sender' => 'Club', 'subject_da' => 'DA', 'subject_en' => 'EN',
            'body_da' => 'Dansk', 'body_en' => 'English', 'tags' => []]);
        self::assertSame(456, $gateway->prepare($message, 123, 0));
        $gateway->preview(456, 'one@example.org');
        self::assertSame(456, $gateway->prepare($message, 123, 456));
        $gateway->release(456, 0);
        $mailings = array_values(array_filter($seen, fn ($call) => in_array($call[0], ['POST', 'PUT']) && in_array($call[1], ['/v3/mailings', '/v3/mailings/456'])));
        self::assertCount(2, $mailings);
        foreach ($mailings as $call) {
            self::assertSame(self::FLOW, $call[2]['settings']['unsubscribe_form_id']);
            self::assertStringContainsString('href="{UNSUBSCRIBE}"', $call[2]['content']['html']);
            self::assertStringContainsString('{UNSUBSCRIBE}', $call[2]['content']['text']);
        }
        self::assertSame('/v3/mailings/456/sendpreview', $seen[4][1]);
        self::assertSame('/v3/mailings/456/release', $seen[array_key_last($seen)][1]);
    }

    public function testIgnoredSelectionBlocksPreviewAndRelease(): void
    {
        $gateway = new CleverReachGateway(fn () => 'fixture', $this->config(), function ($method, $path) {
            self::assertSame('GET', $method);
            return str_starts_with($path, '/flow/') ? $this->flow() : ['unsubscribe_form_id' => '432342'];
        });
        foreach (['preview', 'release'] as $action) {
            try {
                $action === 'preview' ? $gateway->preview(456, 'one@example.org') : $gateway->release(456, 0);
                self::fail('Expected rejected selection');
            } catch (\RuntimeException $e) {
                self::assertSame('COM_INTERCOM_INVALID_UNSUBSCRIBE', $e->getMessage());
            }
        }
    }

    public function testMissingScopeFailsClosedWithoutSending(): void
    {
        $gateway = new CleverReachGateway(fn () => 'fixture', $this->config(), function ($method) {
            self::assertSame('GET', $method);
            throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
        });
        $this->expectExceptionMessage('COM_INTERCOM_PROVIDER_ERROR');
        $gateway->release(456, 0);
    }

    public function testLegacyFormMustAlsoBelongToTheSelectedList(): void
    {
        $gateway = new CleverReachGateway(fn () => 'fixture', $this->config(), function ($method, $path) {
            self::assertSame('GET', $method);
            self::assertSame('/v3/groups/758666/forms', $path);
            return [['id' => '432342', 'customer_tables_id' => '758666']];
        });
        $gateway->assertUnsubscribeForm('432342', 758666);
        $this->expectExceptionMessage('COM_INTERCOM_INVALID_UNSUBSCRIBE');
        $gateway->assertUnsubscribeForm('999', 758666);
    }
}
