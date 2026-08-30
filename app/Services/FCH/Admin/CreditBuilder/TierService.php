<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Http\Resources\FCH\CreditBuilder\TierResource;
use App\Models\CreditBuilder\Tier;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Log;

class TierService
{
    /**
     * List Tiers with pagination and filtering.
     */
    use PaginatesApi;

    /**
     * List Tiers with pagination and filtering.
     */
    public function list(array $filters)
    {
        $query = Tier::query();


        // 🔍 Search filter
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('subtitle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('order', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%");
            });
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sortField = $filters['sort_field'] ?? 'order';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'name' => 'tiers.name',
                    'title' => 'tiers.title',
                    'subtitle' => 'tiers.subtitle',
                    'order' => 'tiers.order',
                    'status' => 'tiers.status',
                    'description' => 'tiers.description',
                ],
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);
        return $this->respondWithPaginationResource(
            $paginator,
            TierResource::class,
            __('tier.success_list')
        );
    }

    /**
     * Store a new Tier.
     */
    public function store(array $data): Tier
    {
        $data['created_by'] = auth()->id();

        // Auto-assign order as next sequential number
        if (!isset($data['order']) || empty($data['order'])) {
            $maxOrder = Tier::max('order') ?? 0;
            $data['order'] = $maxOrder + 1;
        }

        if (!isset($data['has_vendor']) || empty($data['has_vendor'])) {
            $data['has_vendor'] = false;
        } else {
            $data['has_vendor'] = true;
        }

        if ($data['description'] == '<p><br></p>') {
            $data['description'] = null;
        }

        return Tier::create($data);
    }

    /**
     * Update a Tier.
     */
    public function update(Tier $tier, array $data): Tier
    {
        $data['updated_by'] = auth()->id();

        if ($data['description'] == '<p><br></p>') {
            $data['description'] = null;
        }

        if (!isset($data['has_vendor']) || empty($data['has_vendor'])) {
            $data['has_vendor'] = false;
        } else {
            $data['has_vendor'] = true;
        }

        $tier->update($data);
        return $tier;
    }

    /**
     * Find Tier by ID.
     */
    public function findById(int $id): ?Tier
    {
        return Tier::with('tasks')->find($id);
    }

    /**
     * Delete Tiers.
     */
    public function delete(array $ids): bool
    {
        try {
            Tier::whereIn('id', $ids)->update(['deleted_by' => auth()->id()]);
            return Tier::destroy($ids) > 0;
        } catch (\Exception $e) {
            Log::error('Values deletion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get tier wise task list with status for a specific borrower.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBorrowerTierTaskList(int $borrowerId)
    {
        $borrower = \App\Models\Borrower\Borrower::find($borrowerId);

        if (!$borrower) {
            return \App\Helpers\ApiResponse::notFound(__('borrower.not_found'));
        }

        $tierData = Tier::with([
            'tasks' => function ($query) {
                $query->orderBy('order', 'asc');
            },
            'tasks.latestBorrowerTierTask' => function ($query) use ($borrower) {
                $query->where('borrower_id', $borrower->id)->whereNull('migrated_task_id');
            },
            'tasks.vendor'
        ])
            ->orderBy('order', 'asc')->get();

        $response = \App\Http\Resources\Borrower\CreditBuilder\TierResource::collection($tierData)->resolve();

        $migratedTask = \App\Models\Borrower\MigratedTask::with([
            'task.vendor',
            'task.latestBorrowerTierTask' => function ($query) use ($borrower) {
                $query->where('borrower_id', $borrower->id)->whereNotNull('migrated_task_id');
            },
        ])->where('borrower_id', $borrower->id)->get();


        if ($migratedTask->isNotEmpty()) {

            foreach ($response as $key => $res) {
                $newTasks = [];
                foreach ($migratedTask as $mTask) {
                    if ($mTask->tier_to == $res['id']) {

                        /** @var \App\Models\CreditBuilder\BorrowerTaskVerificationRequest|null $borrower_task */
                        $borrower_task = $mTask->task?->latestBorrowerTierTask?->where('migrated_task_id', $mTask->id)->first();

                        $newTasks[] = [
                            'id' => $mTask->task->id,
                            'title' => $mTask->task->title,
                            'subtitle' => $mTask->task->subtitle,
                            'description' => $mTask->task->description,
                            'status' => $borrower_task ? $borrower_task->status : \App\Models\CreditBuilder\BorrowerTaskVerificationRequest::PENDING,
                            'note' => $borrower_task ? $borrower_task->note : null,
                            'completed_at' => ($borrower_task && $borrower_task->verified_at) ? date('d M Y', strtotime($borrower_task->verified_at)) : null,
                            'url' => $mTask->task->url,
                            'logo' => $mTask->task->logo ? asset('/' . encodeUrl($mTask->task->logo)) : null,
                            'button_title' => $mTask->task->button_title,
                            'estimated_completion_days' => $mTask->task->estimated_completion_days,
                            'order' => $mTask->task->order,
                            'vendor' => \App\Http\Resources\FCH\CreditBuilder\CreditBuilderVendorResource::make($mTask->task?->vendor),
                            'migrated_task_id' => $mTask->id,
                        ];
                    }
                }
                if (count($newTasks)) {
                    $response[$key]['tasks'] = $newTasks;
                }
            }
        }

        return \App\Helpers\ApiResponse::success(
            $response,
            __('borrower.tier_task_list_retrieved')
        );
    }

    /**
     * Get credit builder overview data for a specific borrower.
     */
    public function getBorrowerProgress(int $borrowerId): array
    {
        $borrower = \App\Models\Borrower\Borrower::with('tier')->find($borrowerId);

        if (!$borrower) {
            throw new \Exception(__('borrower.not_found'));
        }

        $currentTier = $borrower->tier;
        $tiers = Tier::orderBy('order', 'asc')->withCount('tasks')->get();

        // Total Program Stats
        $migratableTask = \App\Models\Borrower\MigratedTask::where('borrower_id', $borrower->id)->count();
        $totalProgramTasks = $tiers->sum('tasks_count') + $migratableTask;
        $totalCompletedTasks = \App\Models\CreditBuilder\BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
            ->where('status', \App\Models\CreditBuilder\BorrowerTaskVerificationRequest::COMPLETED)
            ->count();

        $totalTasksRemaining = max(0, $totalProgramTasks - $totalCompletedTasks);
        $totalCompletePercentage = $totalProgramTasks > 0 ? round(($totalCompletedTasks / $totalProgramTasks) * 100) : 0;

        // Current Tier Stats
        $tasksRemaining = 0;
        $taskCompletePercentage = 0;
        $currentTierEstimatedDays = 0;
        $currentTierCompletedTasks = 0;

        if ($currentTier) {
            $currentTierTotalTasks = $currentTier->tasks()->count();

            // Get completed tasks IDs for this borrower in this tier
            $completedTaskIds = \App\Models\CreditBuilder\BorrowerTaskVerificationRequest::where('borrower_id', $borrower->id)
                ->where('tier_id', $currentTier->id)
                ->where('status', \App\Models\CreditBuilder\BorrowerTaskVerificationRequest::COMPLETED)
                ->pluck('task_id');

            $currentTierCompletedTasks = $completedTaskIds->count();

            $tasksRemaining = max(0, $currentTierTotalTasks - $currentTierCompletedTasks);

            $currentTierEstimatedDays = $currentTier->tasks()
                ->whereNotIn('id', $completedTaskIds)
                ->sum('estimated_completion_days');

            if ($currentTierTotalTasks > 0) {
                $taskCompletePercentage = round(($currentTierCompletedTasks / $currentTierTotalTasks) * 100);
            } else {
                $taskCompletePercentage = 100; // If current tier has no tasks, it is effectively complete
            }
        }

        return [
            'current_tier' => $currentTier,
            'tiers' => $tiers,
            'tasks_remaining_count' => $tasksRemaining,
            'task_complete_percentage' => $taskCompletePercentage,
            'total_tasks_remaining' => $totalTasksRemaining,
            'total_complete_percentage' => $totalCompletePercentage,
            'current_tier_estimated_completion_days' => (int) $currentTierEstimatedDays,
            'current_tier_completed_tasks' => $currentTierCompletedTasks,
            'total_completed_tasks' => $totalCompletedTasks,
        ];
    }
}
