<?php

namespace Tests\Feature\FileManager;

use App\Http\Controllers\Api\V1\Global\FileManager\FileManagerController;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use App\Support\Files\Disk;
use App\Support\Files\Folder;
use Tests\TestCase;

/**
 * /api/v1/filemanager/* - the folder browser both portals embed. Every call
 * names a root folder on the public disk as {entity_type}/{entity_id} (or the
 * whole {entity_type} with get_all), and works on paths below it.
 */
#[CoversClass(FileManagerController::class)]
class FileManagerApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $partnerAdmin;
    private array $root = ['entity_type' => 'partners', 'entity_id' => 7];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->partnerAdmin);
    }

    private function fm(string $method, string $action, array $data = [])
    {
        return $this->json($method, "/api/v1/filemanager/{$action}", array_merge($this->root, $data));
    }

    #[Test]
    public function every_action_needs_a_signed_in_user(): void
    {
        $this->app['auth']->forgetGuards();

        foreach (['list', 'tree', 'upload', 'folder', 'delete', 'paste', 'download'] as $action) {
            $this->postJson("/api/v1/filemanager/{$action}")->assertUnauthorized();
        }
        $this->putJson('/api/v1/filemanager/rename')->assertUnauthorized();
    }

    #[Test]
    public function the_root_folder_is_named_by_entity_type_and_id(): void
    {
        $this->postJson('/api/v1/filemanager/list', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entity_type', 'entity_id']);
    }

    #[Test]
    public function an_upload_lands_in_the_root_folder_under_its_name_then_the_time(): void
    {
        $response = $this->fm('POST', 'upload', [
            'path' => '/',
            'file' => UploadedFile::fake()->create('Q1 report (final).pdf', 20, 'application/pdf'),
        ])->assertOk();

        $name = $response->json('data.name');
        $this->assertMatchesRegularExpression('/^Q1_report__final__\d{10}\.pdf$/', $name);
        Storage::disk('uploads')->assertExists("partners/7/{$name}");
    }

    #[Test]
    public function two_uploads_of_the_same_name_in_the_same_second_are_both_kept_the_second_numbered(): void
    {
        $this->freezeTime();

        $first = $this->fm('POST', 'upload', ['path' => '/', 'file' => UploadedFile::fake()->createWithContent('bank.pdf', 'one')])->json('data.name');
        $second = $this->fm('POST', 'upload', ['path' => '/', 'file' => UploadedFile::fake()->createWithContent('bank.pdf', 'two')])->json('data.name');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^bank_\d{10}_1\.pdf$/', $second);
        $this->assertSame('one', Storage::disk('uploads')->get("partners/7/{$first}"));
        $this->assertSame('two', Storage::disk('uploads')->get("partners/7/{$second}"));
    }

    #[Test]
    public function the_list_shows_folders_and_files_with_paths_relative_to_the_root(): void
    {
        Storage::disk('uploads')->put('partners/7/contract.pdf', 'pdf');
        Storage::disk('uploads')->makeDirectory('partners/7/Statements');

        $items = collect($this->fm('POST', 'list', ['path' => '/'])->assertOk()->json('data'))->keyBy('name');

        $this->assertTrue($items['Statements']['isDirectory']);
        $this->assertSame('/Statements', $items['Statements']['path']);
        $this->assertFalse($items['contract.pdf']['isDirectory']);
        $this->assertSame('/contract.pdf', $items['contract.pdf']['path']);
    }

    #[Test]
    public function a_folder_is_created_once(): void
    {
        $this->fm('POST', 'folder', ['path' => '', 'name' => 'Tax'])->assertOk()->assertJsonPath('data.path', '/Tax');
        Storage::disk('uploads')->assertExists('partners/7/Tax');

        $this->fm('POST', 'folder', ['path' => '', 'name' => 'Tax'])->assertStatus(422);
    }

    #[Test]
    public function the_tree_lists_nested_folders(): void
    {
        Storage::disk('uploads')->makeDirectory('partners/7/2026/Q1');

        $this->fm('POST', 'tree', ['path' => '/'])
            ->assertOk()
            ->assertJsonPath('data.0.name', '2026')
            ->assertJsonPath('data.0.children.0.path', '/2026/Q1');
    }

    #[Test]
    public function an_item_is_renamed_in_place(): void
    {
        Storage::disk('uploads')->put('partners/7/old.pdf', 'pdf');

        $this->fm('PUT', 'rename', ['path' => '/old.pdf', 'newName' => 'new.pdf'])->assertOk();

        Storage::disk('uploads')->assertMissing('partners/7/old.pdf');
        Storage::disk('uploads')->assertExists('partners/7/new.pdf');
    }

    #[Test]
    public function renaming_a_missing_item_or_onto_an_existing_name_fails(): void
    {
        Storage::disk('uploads')->put('partners/7/a.pdf', 'a');
        Storage::disk('uploads')->put('partners/7/b.pdf', 'b');

        $this->fm('PUT', 'rename', ['path' => '/ghost.pdf', 'newName' => 'x.pdf'])->assertNotFound();
        $this->fm('PUT', 'rename', ['path' => '/a.pdf', 'newName' => 'b.pdf'])->assertStatus(422);
    }

    #[Test]
    public function files_and_folders_are_deleted(): void
    {
        Storage::disk('uploads')->put('partners/7/a.pdf', 'a');
        Storage::disk('uploads')->put('partners/7/Old/b.pdf', 'b');

        $this->fm('POST', 'delete', ['paths' => ['/a.pdf', '/Old']])->assertOk();

        Storage::disk('uploads')->assertMissing('partners/7/a.pdf');
        Storage::disk('uploads')->assertMissing('partners/7/Old');
    }

    #[Test]
    public function pasting_a_copy_keeps_the_original_and_names_a_clash_as_a_copy(): void
    {
        Storage::disk('uploads')->put('partners/7/a.pdf', 'a');
        Storage::disk('uploads')->makeDirectory('partners/7/Archive');
        Storage::disk('uploads')->put('partners/7/Archive/a.pdf', 'older');

        $this->fm('POST', 'paste', [
            'files' => [['path' => '/a.pdf']],
            'destinationPath' => '/Archive',
            'operation' => 'copy',
        ])->assertOk()->assertJsonPath('data.0.name', 'a - Copy.pdf');

        Storage::disk('uploads')->assertExists('partners/7/a.pdf');
        Storage::disk('uploads')->assertExists('partners/7/Archive/a - Copy.pdf');
    }

    #[Test]
    public function pasting_a_move_removes_the_original(): void
    {
        Storage::disk('uploads')->put('partners/7/a.pdf', 'a');
        Storage::disk('uploads')->makeDirectory('partners/7/Archive');

        $this->fm('POST', 'paste', [
            'files' => [['path' => '/a.pdf']],
            'destinationPath' => '/Archive',
            'operation' => 'move',
        ])->assertOk();

        Storage::disk('uploads')->assertMissing('partners/7/a.pdf');
        Storage::disk('uploads')->assertExists('partners/7/Archive/a.pdf');
    }

    #[Test]
    public function a_file_downloads_under_its_own_name(): void
    {
        Storage::disk('uploads')->put('partners/7/contract.pdf', 'contract body');

        $response = $this->fm('POST', 'download', ['path' => '/contract.pdf'])->assertOk();

        $this->assertStringContainsString('contract.pdf', $response->headers->get('Content-Disposition'));
    }

    /** Defect (O8): a missing file threw an exception the API answered with a 500. */
    #[Test]
    public function downloading_a_file_that_is_not_there_is_a_404(): void
    {
        $this->fm('POST', 'download', ['path' => '/no-such-file.pdf'])->assertNotFound();
    }

    #[Test]
    public function dot_dot_segments_cannot_climb_out_of_the_root_folder(): void
    {
        Storage::disk('uploads')->put('partners/8/secret.pdf', 'not yours');

        $items = collect($this->fm('POST', 'list', ['path' => '/../8'])->assertOk()->json('data'))->pluck('name');

        $this->assertNotContains('secret.pdf', $items->all());
    }

    /**
     * Defect: the root folder came from the request alone, so a borrower could
     * name any other borrower's folder - or every borrower's, with get_all.
     * The borrower portal only ever opens the signed-in borrower's own folder.
     */
    #[Test]
    public function a_borrower_works_only_in_their_own_borrower_folder(): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->partner);
        $other = $this->makeBorrower($this->partner);
        Storage::disk('uploads')->put("borrowers/{$other->id}/statement.pdf", 'not yours');
        Storage::disk('uploads')->put("borrowers/{$borrowerUser->entity_id}/mine.pdf", 'mine');
        $this->actingAsUser($borrowerUser);

        $this->postJson('/api/v1/filemanager/list', ['entity_type' => 'borrowers', 'entity_id' => $borrowerUser->entity_id, 'path' => '/'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'mine.pdf');

        $this->postJson('/api/v1/filemanager/list', ['entity_type' => 'borrowers', 'entity_id' => $other->id, 'path' => '/'])
            ->assertForbidden();
        $this->postJson('/api/v1/filemanager/download', ['entity_type' => 'borrowers', 'entity_id' => $other->id, 'path' => '/statement.pdf'])
            ->assertForbidden();
        $this->postJson('/api/v1/filemanager/list', ['entity_type' => 'borrowers', 'entity_id' => $borrowerUser->entity_id, 'get_all' => true, 'path' => '/'])
            ->assertForbidden();
        $this->postJson('/api/v1/filemanager/list', ['entity_type' => 'partners', 'entity_id' => $this->partner->id, 'path' => '/'])
            ->assertForbidden();
    }

    // ── Borrower and staff folders across partners (findings D2) ──────────

    /**
     * Defect (findings D2): the folder a call works in came from the request
     * with no ownership check on the partner side, so a partner (or its staff)
     * could list, download, upload to and delete in another partner's borrower
     * and staff folders - or every borrower's at once with get_all. A non-host
     * partner now reaches only its own borrowers' and staff's folders.
     */
    #[Test]
    public function a_non_host_partner_and_its_staff_reach_only_their_own_borrowers_and_staff(): void
    {
        $myBorrower = $this->makeBorrower($this->partner);
        $myStaff = $this->makeStaffUser($this->partner);
        Storage::disk('uploads')->put("borrowers/{$myBorrower->id}/mine.pdf", 'mine');
        Storage::disk('uploads')->put("employees/{$myStaff->entity_id}/w4.pdf", 'mine');

        foreach ([$this->partnerAdmin, $myStaff] as $caller) {
            $this->actingAsUser($caller);

            $this->postJson('/api/v1/filemanager/list', ['entity_type' => 'borrowers', 'entity_id' => $myBorrower->id, 'path' => '/'])
                ->assertOk()->assertJsonPath('data.0.name', 'mine.pdf');
            $this->postJson('/api/v1/filemanager/list', ['entity_type' => 'employees', 'entity_id' => $myStaff->entity_id, 'path' => '/'])
                ->assertOk()->assertJsonPath('data.0.name', 'w4.pdf');
        }
    }

    #[Test]
    public function another_partners_borrower_and_staff_folders_are_refused_for_every_action(): void
    {
        $other = $this->makeCertifiedPartner();
        $theirBorrower = $this->makeBorrower($other);
        $theirStaff = $this->makeStaffUser($other);
        Storage::disk('uploads')->put("borrowers/{$theirBorrower->id}/statement.pdf", 'not yours');
        Storage::disk('uploads')->put("employees/{$theirStaff->entity_id}/w4.pdf", 'not yours');

        foreach ([$this->partnerAdmin, $this->makeStaffUser($this->partner)] as $caller) {
            $this->actingAsUser($caller);

            foreach ([['borrowers', $theirBorrower->id, 'statement.pdf'], ['employees', $theirStaff->entity_id, 'w4.pdf']] as [$type, $id, $file]) {
                $root = ['entity_type' => $type, 'entity_id' => $id];

                $this->postJson('/api/v1/filemanager/list', $root + ['path' => '/'])->assertForbidden();
                $this->postJson('/api/v1/filemanager/tree', $root + ['path' => '/'])->assertForbidden();
                $this->postJson('/api/v1/filemanager/download', $root + ['path' => "/{$file}"])->assertForbidden();
                $this->postJson('/api/v1/filemanager/upload', $root + ['path' => '/', 'file' => UploadedFile::fake()->create('x.pdf', 1)])->assertForbidden();
                $this->postJson('/api/v1/filemanager/folder', $root + ['path' => '/', 'name' => 'Hijack'])->assertForbidden();
                $this->putJson('/api/v1/filemanager/rename', $root + ['path' => "/{$file}", 'newName' => 'gone.pdf'])->assertForbidden();
                $this->postJson('/api/v1/filemanager/delete', $root + ['paths' => ["/{$file}"]])->assertForbidden();
                $this->postJson('/api/v1/filemanager/paste', $root + ['files' => [['path' => "/{$file}"]], 'destinationPath' => '/', 'operation' => 'copy'])->assertForbidden();
            }
        }

        Storage::disk('uploads')->assertExists("borrowers/{$theirBorrower->id}/statement.pdf");
        Storage::disk('uploads')->assertExists("employees/{$theirStaff->entity_id}/w4.pdf");
        Storage::disk('uploads')->assertMissing("borrowers/{$theirBorrower->id}/Hijack");
    }

    /** get_all opens every borrower's (or every staff member's) folder at once. */
    #[Test]
    public function a_non_host_partner_cannot_open_every_borrower_or_staff_folder(): void
    {
        $myBorrower = $this->makeBorrower($this->partner);

        foreach (['borrowers', 'employees'] as $type) {
            $this->postJson('/api/v1/filemanager/list', ['entity_type' => $type, 'entity_id' => $myBorrower->id, 'get_all' => true, 'path' => '/'])
                ->assertForbidden();
        }
    }

    /** The host's team works on every partner's borrowers and staff. */
    #[Test]
    public function the_hosts_team_reaches_any_partners_borrowers_and_staff(): void
    {
        $host = Partner::where('is_host', true)->firstOrFail();
        $theirBorrower = $this->makeBorrower($this->makeCertifiedPartner());
        $theirStaff = $this->makeStaffUser($this->partner);
        Storage::disk('uploads')->put("borrowers/{$theirBorrower->id}/statement.pdf", 'pdf');
        Storage::disk('uploads')->put("employees/{$theirStaff->entity_id}/w4.pdf", 'pdf');

        foreach ([$this->makePartnerAdmin($host), $this->makeStaffUser($host)] as $caller) {
            $this->actingAsUser($caller);

            $this->postJson('/api/v1/filemanager/list', ['entity_type' => 'borrowers', 'entity_id' => $theirBorrower->id, 'path' => '/'])
                ->assertOk()->assertJsonPath('data.0.name', 'statement.pdf');
            $this->postJson('/api/v1/filemanager/list', ['entity_type' => 'employees', 'entity_id' => $theirStaff->entity_id, 'path' => '/'])
                ->assertOk()->assertJsonPath('data.0.name', 'w4.pdf');
        }
    }

    /**
     * The Partner Documents tab opens its file manager on the partner's
     * private documents folder: a file is kept there (never on the public
     * disk), listed from there, and opened through a signed link.
     */
    #[Test]
    public function the_hosts_team_keeps_a_partners_documents_in_their_private_folder(): void
    {
        $host = Partner::where('is_host', true)->firstOrFail();
        $this->actingAsUser($this->makePartnerAdmin($host));
        $root = ['entity_type' => Folder::PARTNER_DOCUMENTS, 'entity_id' => $this->partner->id];

        $this->postJson('/api/v1/filemanager/upload', $root + ['path' => '/', 'file' => UploadedFile::fake()->create('w9.pdf', 1)])->assertOk();

        $kept = Storage::disk(Disk::UPLOADS)->files(Folder::PARTNER_DOCUMENTS . "/{$this->partner->id}");
        $this->assertCount(1, $kept);
        Storage::disk(Disk::LOCAL)->assertExists($kept[0]);
        Storage::disk(Disk::PUBLIC)->assertMissing($kept[0]);

        $list = $this->postJson('/api/v1/filemanager/list', $root + ['path' => '/'])->assertOk();
        $this->assertSame(basename($kept[0]), $list->json('data.0.name'));
        $this->assertStringContainsString('/private-files/' . Folder::PARTNER_DOCUMENTS . "/{$this->partner->id}/", $list->json('data.0.url'));
        $this->assertStringContainsString('signature=', $list->json('data.0.url'));
    }

    /** Partner documents are the host team's, as the Documents tab is: no partner opens them, its own included. */
    #[Test]
    public function a_non_host_partner_cannot_open_any_partners_documents_folder(): void
    {
        Storage::disk(Disk::UPLOADS)->put(Folder::PARTNER_DOCUMENTS . "/{$this->partner->id}/w9.pdf", 'pdf');

        foreach ([$this->partner->id, $this->makeCertifiedPartner()->id] as $partnerId) {
            $root = ['entity_type' => Folder::PARTNER_DOCUMENTS, 'entity_id' => $partnerId];

            $this->postJson('/api/v1/filemanager/list', $root + ['path' => '/'])->assertForbidden();
            $this->postJson('/api/v1/filemanager/upload', $root + ['path' => '/', 'file' => UploadedFile::fake()->create('x.pdf', 1)])->assertForbidden();
            $this->postJson('/api/v1/filemanager/download', $root + ['path' => '/w9.pdf'])->assertForbidden();
        }
    }
}
