<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Enum\Priority;
use App\Enum\Status;
use App\Exception\InvalidSearchParameterException;
use App\Http\TicketSearchParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TicketSearchParserTest extends TestCase
{
    private TicketSearchParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TicketSearchParser();
    }

    public function testValidValuesAreParsed(): void
    {
        $criteria = $this->parser->parse(Request::create('/api/tickets', 'GET', [
            'status' => 'assigned',
            'priority' => 'urgent',
            'assigneeId' => '2',
            'createdFrom' => '2026-06-01T14:00:00+02:00',
            'createdTo' => '2026-06-01T13:00:00Z',
            'cursor' => 'abc',
            'limit' => '25',
        ]));

        $this->assertSame(Status::Assigned, $criteria->status);
        $this->assertSame(Priority::Urgent, $criteria->priority);
        $this->assertSame(2, $criteria->assigneeId);
        $this->assertSame('2026-06-01T12:00:00+00:00', $criteria->createdFrom?->format(\DateTimeInterface::ATOM));
        $this->assertSame('2026-06-01T13:00:00+00:00', $criteria->createdTo?->format(\DateTimeInterface::ATOM));
        $this->assertSame('abc', $criteria->cursor);
        $this->assertSame(25, $criteria->limit);
    }

    public function testDefaultsAreApplied(): void
    {
        $criteria = $this->parser->parse(Request::create('/api/tickets', 'GET'));

        $this->assertNull($criteria->status);
        $this->assertNull($criteria->priority);
        $this->assertNull($criteria->assigneeId);
        $this->assertNull($criteria->createdFrom);
        $this->assertNull($criteria->createdTo);
        $this->assertNull($criteria->cursor);
        $this->assertSame(50, $criteria->limit);
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->assertInvalid(['status' => 'critical'], 'status');
    }

    public function testUnknownPriorityIsRejected(): void
    {
        $this->assertInvalid(['priority' => 'critical'], 'priority');
    }

    public function testInvalidAssigneeIdIsRejected(): void
    {
        $this->assertInvalid(['assigneeId' => '0'], 'assigneeId');
        $this->assertInvalid(['assigneeId' => 'abc'], 'assigneeId');
    }

    public function testInvalidDatesAreRejected(): void
    {
        $this->assertInvalid(['createdFrom' => 'not-a-date'], 'createdFrom');
        $this->assertInvalid(['createdFrom' => '2026-13-01T00:00:00+00:00'], 'createdFrom');
        $this->assertInvalid(['createdTo' => '2026-06-01'], 'createdTo');
    }

    public function testCreatedFromMustBeBeforeCreatedTo(): void
    {
        $this->assertInvalid([
            'createdFrom' => '2026-06-01T12:00:00+00:00',
            'createdTo' => '2026-06-01T12:00:00+00:00',
        ], 'createdFrom');

        $this->assertInvalid([
            'createdFrom' => '2026-06-02T12:00:00+00:00',
            'createdTo' => '2026-06-01T12:00:00+00:00',
        ], 'createdFrom');
    }

    public function testLimitMustBeBetweenOneAndOneHundred(): void
    {
        $this->assertInvalid(['limit' => '0'], 'limit');
        $this->assertInvalid(['limit' => '101'], 'limit');
        $this->assertInvalid(['limit' => 'abc'], 'limit');
    }

    /**
     * @param array<string, string> $query
     */
    private function assertInvalid(array $query, string $parameter): void
    {
        try {
            $this->parser->parse(Request::create('/api/tickets', 'GET', $query));
        } catch (InvalidSearchParameterException $exception) {
            $this->assertStringContainsString($parameter, $exception->getMessage());

            return;
        }

        self::fail(sprintf('Le paramètre "%s" aurait dû être rejeté.', $parameter));
    }
}
