<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvgateTicketCommentRepository;
use App\Repositories\InvgateTicketRepository;

final class InvgateCommentSyncService
{
    /** @var InvgateClient */
    private $client;

    /** @var InvgateTicketRepository */
    private $ticketsRepo;

    /** @var InvgateTicketCommentRepository */
    private $commentsRepo;

    public function __construct(
        InvgateClient $client,
        InvgateTicketRepository $ticketsRepo,
        InvgateTicketCommentRepository $commentsRepo
    ) {
        $this->client = $client;
        $this->ticketsRepo = $ticketsRepo;
        $this->commentsRepo = $commentsRepo;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        InvgateTicketRepository $ticketsRepo,
        InvgateTicketCommentRepository $commentsRepo
    ): self {
        $ig = InvgateTicketSyncService::parseInvgateConfig($config);
        $client = new InvgateClient(
            $ig['server_url'],
            $ig['user'],
            $ig['password'],
            $ig['limit']
        );

        return new self($client, $ticketsRepo, $commentsRepo);
    }

    /**
     * @return array{
     *   ok: bool,
     *   tickets_total: int,
     *   tickets_ok: int,
     *   comments_inserted: int,
     *   comments_skipped: int,
     *   errors: list<array{ticket_id: int, request_id: int, error: string}>
     * }
     */
    public function run(): array
    {
        $tickets = $this->ticketsRepo->listForCommentSync();
        $result = [
            'ok' => true,
            'tickets_total' => count($tickets),
            'tickets_ok' => 0,
            'comments_inserted' => 0,
            'comments_skipped' => 0,
            'errors' => [],
        ];

        if ($tickets === []) {
            return $result;
        }

        foreach ($tickets as $ticket) {
            $ticketId = (int) $ticket['id'];
            $requestId = (int) $ticket['invgate_incident_id'];

            try {
                $comments = $this->client->fetchIncidentComments($requestId);
                foreach ($comments as $comment) {
                    if (!is_array($comment)) {
                        $result['comments_skipped']++;
                        continue;
                    }
                    if ($this->commentsRepo->insertIfNew($ticketId, $comment)) {
                        $result['comments_inserted']++;
                    } else {
                        $result['comments_skipped']++;
                    }
                }
                $result['tickets_ok']++;
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'ticket_id' => $ticketId,
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($result['errors'] !== []) {
            $result['ok'] = $result['tickets_ok'] > 0;
        }

        return $result;
    }
}
