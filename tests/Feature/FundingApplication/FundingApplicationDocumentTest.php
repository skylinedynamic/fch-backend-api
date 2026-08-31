<?php

namespace Tests\Feature\FundingApplication;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FundingApplicationDocumentTest extends TestCase
{
    protected Borrower $borrower;

    protected User $user;

    protected FundingApplication $application;

    protected BorrowerDocType $docType;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Storage::fake('public');

        $this->borrower = Borrower::factory()->create();
        $this->user = User::factory()->borrower($this->borrower)->create();
        $this->application = FundingApplication::factory()->create(['borrower_id' => $this->borrower->id]);
        $this->docType = BorrowerDocType::create(['name' => 'Bank Statement']);
    }

    public function test_uploading_a_document_stores_it_and_returns_a_resource_collection(): void
    {
        $file = UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/documents/upload', [
                'funding_application_id' => $this->application->id,
                'document_type_id' => $this->docType->id,
                'files' => [$file],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', true);

        $this->assertDatabaseHas('funding_application_documents', [
            'funding_application_id' => $this->application->id,
            'borrower_id' => $this->borrower->id,
            'original_name' => 'statement.pdf',
        ]);
    }

    public function test_uploading_a_document_with_a_nonexistent_document_type_fails_validation(): void
    {
        $file = UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/documents/upload', [
                'funding_application_id' => $this->application->id,
                'document_type_id' => 999999,
                'files' => [$file],
            ]);

        $response->assertStatus(422);
    }

    public function test_deleting_a_document_removes_it_from_storage_and_the_database(): void
    {
        Storage::disk('public')->put('funding-application/x/file.pdf', 'contents');

        $document = FundingApplicationDocument::create([
            'funding_application_id' => $this->application->id,
            'borrower_id' => $this->borrower->id,
            'name' => 'file.pdf',
            'path' => 'funding-application/x/file.pdf',
            'disk' => 'public',
            'original_name' => 'file.pdf',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/borrower/funding-application/documents/'.$document->id);

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('funding_application_documents', ['id' => $document->id]);
        Storage::disk('public')->assertMissing('funding-application/x/file.pdf');
    }

    public function test_a_borrower_cannot_delete_another_borrowers_document(): void
    {
        $otherBorrower = Borrower::factory()->create();
        $otherApplication = FundingApplication::factory()->create(['borrower_id' => $otherBorrower->id]);
        $document = FundingApplicationDocument::create([
            'funding_application_id' => $otherApplication->id,
            'borrower_id' => $otherBorrower->id,
            'name' => 'file.pdf',
            'path' => 'funding-application/other/file.pdf',
            'disk' => 'public',
            'original_name' => 'file.pdf',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/borrower/funding-application/documents/'.$document->id);

        $response->assertStatus(500)
            ->assertJsonPath('status', false);

        $this->assertDatabaseHas('funding_application_documents', ['id' => $document->id]);
    }
}
