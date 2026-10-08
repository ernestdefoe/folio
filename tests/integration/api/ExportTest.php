<?php

namespace Ernestdefoe\Folio\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Folio\Event\PreparingPost;
use Ernestdefoe\Folio\Extend\Folio;
use Ernestdefoe\Folio\Tests\fixtures\PlainTextFormat;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /api/folio/discussions/{id}: who may export, and that an export holds
 * exactly the posts the reader could see.
 *
 * Discussion 1 has three comments; the third is hidden. Discussion 2 is
 * hidden, and someone else's. Users: 1 admin, 2 and 3 members.
 */
class ExportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-folio');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'second', 'email' => 'second@machine.local', 'is_email_confirmed' => 1],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Match/day: "review"', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 2],
                ['id' => 2, 'title' => 'Hidden', 'created_at' => Carbon::now(), 'user_id' => 3, 'first_post_id' => 4, 'comment_count' => 1, 'hidden_at' => Carbon::now()],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>The opening post</p></t>'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>A visible reply</p></t>'],
                ['id' => 3, 'discussion_id' => 1, 'number' => 3, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>A hidden reply</p></t>', 'hidden_at' => Carbon::now()],
                ['id' => 4, 'discussion_id' => 2, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Hidden discussion</p></t>'],
            ],
        ]);
    }

    private function export(int $discussion, ?int $actor, array $query = []): ResponseInterface
    {
        return $this->send(
            $this->request('GET', "/api/folio/discussions/$discussion", $actor ? ['authenticatedAs' => $actor] : [])
                ->withQueryParams($query + ['format' => 'markdown'])
        );
    }

    #[Test]
    public function a_guest_may_not_export_until_guests_are_granted_it()
    {
        $this->assertSame(403, $this->export(1, null)->getStatusCode());
    }

    #[Test]
    public function a_guest_may_export_once_granted()
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => Group::GUEST_ID, 'permission' => 'discussion.folioExport']]]);

        $this->assertSame(200, $this->export(1, null)->getStatusCode());
    }

    #[Test]
    public function a_member_exports_only_what_they_can_see()
    {
        $response = $this->export(1, 2);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), $body);
        $this->assertStringContainsString('The opening post', $body);
        $this->assertStringContainsString('A visible reply', $body);
        $this->assertStringNotContainsString('A hidden reply', $body);
    }

    #[Test]
    public function an_admin_who_can_see_hidden_posts_gets_them()
    {
        $this->assertStringContainsString('A hidden reply', (string) $this->export(1, 1)->getBody());
    }

    #[Test]
    public function a_discussion_the_reader_cannot_see_is_not_found()
    {
        $this->assertSame(404, $this->export(2, 2)->getStatusCode());
        $this->assertSame(404, $this->export(999, 1)->getStatusCode());
        $this->assertSame(200, $this->export(2, 1)->getStatusCode());
    }

    #[Test]
    public function the_file_is_never_cached_and_its_name_is_safe()
    {
        $response = $this->export(1, 2);

        $this->assertStringStartsWith('text/markdown', $response->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('attachment; filename="Matchday review.md"; filename*=UTF-8\'\'Matchday%20review.md', $response->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function the_first_post_alone_can_be_exported()
    {
        $body = (string) $this->export(1, 2, ['scope' => 'first'])->getBody();

        $this->assertStringContainsString('The opening post', $body);
        $this->assertStringNotContainsString('A visible reply', $body);
    }

    #[Test]
    public function an_unknown_or_switched_off_format_is_refused()
    {
        $this->setting('ernestdefoe-folio.format_docx', '0');

        $this->assertSame(422, $this->export(1, 1, ['format' => 'exe'])->getStatusCode());
        $this->assertSame(422, $this->export(1, 1, ['format' => 'docx'])->getStatusCode());
    }

    #[Test]
    public function pdf_and_word_files_are_built()
    {
        $pdf = $this->export(1, 1, ['format' => 'pdf']);
        $this->assertSame(200, $pdf->getStatusCode(), (string) $pdf->getBody());
        $this->assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getBody());

        $docx = $this->export(1, 1, ['format' => 'docx']);
        $this->assertSame(200, $docx->getStatusCode(), (string) $docx->getBody());
        $this->assertStringStartsWith('PK', (string) $docx->getBody(), 'A .docx is a zip');
    }

    #[Test]
    public function a_member_is_throttled_between_exports()
    {
        $this->assertSame(200, $this->export(1, 2)->getStatusCode());
        $this->assertSame(429, $this->export(1, 2)->getStatusCode());
        $this->assertSame(200, $this->export(1, 3)->getStatusCode(), 'Someone else is not');
    }

    #[Test]
    public function the_post_limit_is_kept()
    {
        $this->setting('ernestdefoe-folio.max_posts', 1);

        $body = (string) $this->export(1, 2)->getBody();

        $this->assertStringContainsString('The opening post', $body);
        $this->assertStringNotContainsString('A visible reply', $body);
    }

    #[Test]
    public function another_extension_can_add_a_format_and_change_a_post()
    {
        $this->extend(
            (new Folio())->format(PlainTextFormat::class),
            (new Extend\Event())->listen(PreparingPost::class, function (PreparingPost $event) {
                $event->skip = $event->post->number === 2;
            })
        );

        $response = $this->export(1, 2, ['format' => 'txt']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Match/day: "review": 1 posts', (string) $response->getBody());
    }
}
