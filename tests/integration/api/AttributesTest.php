<?php

namespace Ernestdefoe\Folio\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** What the forum and each discussion tell the export button. */
class AttributesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-folio');

        $discussions = $posts = [];
        foreach (range(1, 12) as $id) {
            $discussions[] = ['id' => $id, 'title' => "D$id", 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => $id, 'comment_count' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>x</p></t>'];
        }

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => $discussions,
            Post::class => $posts,
        ]);
    }

    private function get(string $path, ?int $actor = null): array
    {
        $response = $this->send($this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : []));
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function the_forum_lists_the_enabled_formats_in_order()
    {
        $this->setting('ernestdefoe-folio.format_docx', '0');

        $formats = $this->get('/api')['data']['attributes']['folioFormats'];

        $this->assertSame(['pdf', 'markdown'], array_column($formats, 'key'));
        $this->assertTrue($formats[0]['avatars']);
        $this->assertFalse($formats[1]['avatars']);
    }

    #[Test]
    public function only_an_admin_is_told_about_the_pdf_engine()
    {
        $this->assertArrayNotHasKey('folioMpdf', $this->get('/api', 2)['data']['attributes']);
        $this->assertTrue($this->get('/api', 1)['data']['attributes']['folioMpdf'], 'mpdf is installed for these tests');
    }

    #[Test]
    public function each_discussion_says_whether_the_reader_may_export_it()
    {
        $can = fn (?int $actor) => array_unique(array_column(array_column($this->get('/api/discussions', $actor)['data'], 'attributes'), 'canFolioExport'));

        // flarum/testing fails the request when the same query repeats.
        $this->assertSame([false], $can(null));
        $this->assertSame([true], $can(2));
    }
}
