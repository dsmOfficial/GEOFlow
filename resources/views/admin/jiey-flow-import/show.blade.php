@extends('admin.layouts.app')

@php
    $stepKeys = array_keys($steps);
    $currentStepKey = $job->current_step ?: 'queued';
    if ($job->status === 'completed') {
        $currentStepKey = 'completed';
    }
    $currentStepIndex = array_search($currentStepKey, $stepKeys, true);
    $currentStepIndex = $currentStepIndex === false ? -1 : $currentStepIndex;
    $materials = data_get($result, 'materials', []);
    $prompt = data_get($result, 'prompt', []);
    $task = data_get($result, 'task', []);
    $jieyMeta = data_get($materials, 'jiey', []);
    $projectId = (int) ($input['project_id'] ?? data_get($jieyMeta, 'project_id', 0));
    $projectName = (string) ($input['project_name'] ?? '');
@endphp

@section('content')
    <div
        class="px-4 sm:px-0 space-y-8"
        data-jiey-flow-import-page
        data-job-id="{{ $job->id }}"
        data-status="{{ $job->status }}"
        data-finished="{{ $job->isFinished() ? '1' : '0' }}"
        data-status-url="{{ \App\Support\AdminWeb::routePath('admin.jiey-flow-import.status', ['jobId' => $job->id]) }}"
    >
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.jiey-flow-import') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.jiey_flow_import.section.progress') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">
                        #{{ $job->id }} · project {{ $projectId }}
                        @if ($projectName !== '')
                            · {{ $projectName }}
                        @endif
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('admin.jiey-flow-import.history') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="history" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.jiey_flow_import.button.view_history') }}
                </a>
                <a href="{{ route('admin.jiey-flow-import') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.jiey_flow_import.button.new_job') }}
                </a>
            </div>
        </div>

        @if (session('message'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-medium text-green-700">
                {{ session('message') }}
            </div>
        @endif

        <div class="bg-white shadow rounded-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-200">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.jiey_flow_import.section.stage_status') }}</h2>
                        <p class="mt-1 text-sm text-gray-500" data-status-text>
                            {{ __('admin.jiey_flow_import.progress.'.($job->status === 'completed' ? 'completed' : ($job->status === 'failed' ? 'failed' : ($job->status === 'processing' ? 'processing' : 'waiting')))) }}
                        </p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700" data-status-label>
                        {{ __('admin.jiey_flow_import.status.'.$job->status) }}
                    </span>
                </div>
            </div>
            <div class="p-6">
                <div class="flex items-center justify-between text-sm text-gray-500">
                    <span data-current-step-label>{{ __('admin.jiey_flow_import.label.current_step', ['step' => $steps[$currentStepKey] ?? $currentStepKey]) }}</span>
                    <span data-progress-number>{{ (int) $job->progress_percent }}%</span>
                </div>
                <div class="mt-2 h-2 rounded-full bg-gray-200">
                    <div class="h-2 rounded-full bg-blue-600 transition-all" data-progress-bar style="width: {{ max(0, min(100, (int) $job->progress_percent)) }}%"></div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
            <div class="xl:col-span-2 bg-white shadow rounded-2xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.jiey_flow_import.section.workflow') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.jiey_flow_import.section.workflow_desc') }}</p>
                </div>
                <div class="p-6 space-y-3">
                    @foreach ($steps as $stepKey => $stepLabel)
                        @php
                            $stepIndex = array_search($stepKey, $stepKeys, true);
                            $isTerminal = $job->status === 'completed';
                            $isFailedStep = $job->status === 'failed' && $currentStepKey === $stepKey;
                            $isCurrent = ! $isTerminal && $job->status !== 'failed' && $currentStepKey === $stepKey;
                            $isDone = $currentStepIndex !== -1 && $stepIndex !== false && ($isTerminal ? $stepIndex <= $currentStepIndex : $stepIndex < $currentStepIndex);
                            $rowClass = $isCurrent
                                ? 'border-blue-300 bg-blue-50 shadow-sm'
                                : ($isFailedStep ? 'border-red-200 bg-red-50 shadow-sm' : ($isDone ? 'border-green-100 bg-white' : 'border-gray-200 bg-white opacity-70'));
                            $iconClass = $isCurrent
                                ? 'bg-blue-600 text-white'
                                : ($isFailedStep ? 'bg-red-500 text-white' : ($isDone ? 'bg-green-500 text-white' : 'bg-gray-100 text-gray-400'));
                            $iconName = $isDone ? 'check' : ($isFailedStep ? 'x' : ($isCurrent ? 'loader-circle' : 'circle'));
                        @endphp
                        <div class="flex items-start gap-3 rounded-xl border {{ $rowClass }} p-4" data-step-row="{{ $stepKey }}">
                            <div class="mt-0.5 flex h-6 w-6 items-center justify-center rounded-full {{ $iconClass }}">
                                <i data-lucide="{{ $iconName }}" class="w-3.5 h-3.5 {{ $isCurrent ? 'animate-spin' : '' }}"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ $stepLabel }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="xl:col-span-3 space-y-6">
                @if ($job->status === 'failed')
                    <div class="rounded-2xl border border-red-200 bg-red-50 p-6">
                        <h3 class="text-base font-semibold text-red-800">{{ __('admin.jiey_flow_import.error.job_failed') }}</h3>
                        <p class="mt-2 text-sm text-red-700" data-error-message>{{ $job->error_message }}</p>
                    </div>
                @endif

                <div class="bg-white shadow rounded-2xl overflow-hidden" data-result-panel @if($job->status !== 'completed') style="display:none" @endif>
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-xl font-semibold text-gray-900">{{ __('admin.jiey_flow_import.section.result') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.jiey_flow_import.section.result_desc') }}</p>
                    </div>
                    <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="text-gray-500">{{ __('admin.jiey_flow_import.result.knowledge_base') }}</div>
                            <div class="mt-1 font-semibold text-gray-900" data-kb-id>
                                @if (! empty($materials['knowledge_base_id']))
                                    <a class="text-blue-600 hover:text-blue-800" href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $materials['knowledge_base_id']]) }}">#{{ (int) $materials['knowledge_base_id'] }}</a>
                                @else
                                    —
                                @endif
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="text-gray-500">{{ __('admin.jiey_flow_import.result.task') }}</div>
                            <div class="mt-1 font-semibold text-gray-900" data-task-name>{{ data_get($task, 'name', '—') }}</div>
                            <div class="mt-1 text-xs text-gray-500" data-task-id>
                                @if (! empty($task['id']))
                                    ID #{{ (int) $task['id'] }}
                                @endif
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="text-gray-500">{{ __('admin.jiey_flow_import.result.keywords_titles') }}</div>
                            <div class="mt-1 font-semibold text-gray-900" data-keywords-titles>
                                {{ (int) data_get($materials, 'keywords_count', 0) }} / {{ (int) data_get($materials, 'titles_count', 0) }}
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="text-gray-500">{{ __('admin.jiey_flow_import.result.prompt') }}</div>
                            <div class="mt-1 font-semibold text-gray-900" data-prompt-name>{{ data_get($prompt, 'name', '—') }}</div>
                        </div>
                        <div class="rounded-xl border border-gray-100 p-4 md:col-span-2">
                            <div class="text-gray-500">{{ __('admin.jiey_flow_import.result.artifacts') }}</div>
                            <div class="mt-1 font-semibold text-gray-900" data-artifact-ids>
                                @php $ids = data_get($jieyMeta, 'included_artifact_ids', []); @endphp
                                {{ is_array($ids) && $ids !== [] ? implode(', ', $ids) : '—' }}
                            </div>
                            @if (! empty($jieyMeta['preview_url']))
                                <a href="{{ $jieyMeta['preview_url'] }}" target="_blank" rel="noopener" class="mt-2 inline-flex text-sm text-blue-600 hover:text-blue-800">
                                    {{ $jieyMeta['preview_url'] }}
                                </a>
                            @endif
                        </div>
                    </div>
                    <div class="px-6 pb-6 flex flex-wrap gap-3">
                        @if (! empty($materials['knowledge_base_id']))
                            <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $materials['knowledge_base_id']]) }}" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                                {{ __('admin.jiey_flow_import.button.open_knowledge') }}
                            </a>
                        @endif
                        <a href="{{ route('admin.tasks.index') }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            {{ __('admin.jiey_flow_import.button.open_tasks') }}
                        </a>
                    </div>
                </div>

                <div class="bg-white shadow rounded-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.jiey_flow_import.section.input') }}</h3>
                    </div>
                    <dl class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-gray-500">project_id</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ $projectId }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">article_type</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ $job->article_type }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">project_name</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ $projectName !== '' ? $projectName : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">article_count</dt>
                            <dd class="mt-1 font-medium text-gray-900">{{ (int) ($input['article_count'] ?? 0) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const root = document.querySelector('[data-jiey-flow-import-page]');
            if (!root || root.dataset.finished === '1') {
                return;
            }

            const statusUrl = root.dataset.statusUrl;
            const progressBar = root.querySelector('[data-progress-bar]');
            const progressNumber = root.querySelector('[data-progress-number]');
            const statusLabel = root.querySelector('[data-status-label]');
            const statusText = root.querySelector('[data-status-text]');
            const currentStepLabel = root.querySelector('[data-current-step-label]');
            const resultPanel = root.querySelector('[data-result-panel]');
            const errorBox = root.querySelector('[data-error-message]');
            const steps = @json($steps);

            const poll = async () => {
                try {
                    const response = await fetch(statusUrl, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) {
                        return;
                    }
                    const data = await response.json();
                    const percent = Math.max(0, Math.min(100, Number(data.progress_percent || 0)));
                    if (progressBar) progressBar.style.width = percent + '%';
                    if (progressNumber) progressNumber.textContent = percent + '%';
                    if (statusLabel) statusLabel.textContent = data.status;
                    if (currentStepLabel) {
                        const step = data.current_step || 'queued';
                        currentStepLabel.textContent = (steps[step] || step);
                    }
                    if (statusText) {
                        if (data.status === 'completed') statusText.textContent = @json(__('admin.jiey_flow_import.progress.completed'));
                        else if (data.status === 'failed') statusText.textContent = @json(__('admin.jiey_flow_import.progress.failed'));
                        else if (data.status === 'processing') statusText.textContent = @json(__('admin.jiey_flow_import.progress.processing'));
                        else statusText.textContent = @json(__('admin.jiey_flow_import.progress.waiting'));
                    }
                    if (data.is_finished) {
                        window.location.reload();
                    }
                } catch (e) {
                    // ignore transient network errors
                }
            };

            poll();
            setInterval(poll, 2500);
        });
    </script>
@endsection
