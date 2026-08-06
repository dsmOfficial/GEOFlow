@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0 space-y-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.materials.index') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.jiey_flow_import.page_heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.jiey_flow_import.page_subtitle') }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('admin.jiey-flow-import.history') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="history" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.jiey_flow_import.button.view_history') }}
                </a>
                <a href="{{ route('admin.materials.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.jiey_flow_import.button.back_to_materials') }}
                </a>
            </div>
        </div>

        @if (session('message'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-medium text-green-700">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-medium text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        @if (! $jieyReady)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-6 py-5 text-amber-900">
                <div class="flex items-start gap-3">
                    <i data-lucide="triangle-alert" class="mt-0.5 h-5 w-5 shrink-0"></i>
                    <div>
                        <div class="text-base font-semibold">{{ __('admin.jiey_flow_import.config.not_ready_title') }}</div>
                        <p class="mt-2 text-sm leading-6 text-amber-800">{{ $jieyStatusMessage }}</p>
                        <p class="mt-2 text-sm leading-6 text-amber-800">{{ __('admin.jiey_flow_import.config.help') }}</p>
                        <pre class="mt-3 overflow-x-auto rounded-lg bg-amber-100/70 px-4 py-3 text-xs text-amber-950">GEOFLOW_JIEY_ENABLED=true
GEOFLOW_JIEY_API_BASE=https://api.gongxingglobal.com
GEOFLOW_JIEY_INTERNAL_SECRET=your-secret</pre>
                    </div>
                </div>
            </div>
        @endif

        @if (! $aiModelReady)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-6 py-5 text-amber-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="flex items-center text-base font-semibold">
                            <i data-lucide="triangle-alert" class="mr-2 h-5 w-5"></i>
                            {{ __('admin.jiey_flow_import.ai_required.title') }}
                        </div>
                        <p class="mt-2 text-sm leading-6 text-amber-800">{{ __('admin.jiey_flow_import.ai_required.desc') }}</p>
                    </div>
                    <a href="{{ $aiModelConfigUrl }}" class="inline-flex items-center justify-center rounded-xl bg-amber-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-amber-700">
                        <i data-lucide="settings" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.jiey_flow_import.ai_required.button') }}
                    </a>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.jiey-flow-import.store') }}" class="bg-white shadow rounded-2xl overflow-hidden">
            @csrf
            <div class="px-6 py-6 lg:px-8 border-b border-gray-200">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center rounded-full bg-violet-50 px-3 py-1 text-sm font-medium text-violet-700">
                        <i data-lucide="boxes" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.jiey_flow_import.badge') }}
                    </span>
                    @if ($jieyReady)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                            {{ __('admin.jiey_flow_import.config.ready') }}
                        </span>
                    @endif
                </div>
                <h2 class="mt-5 text-2xl font-bold text-gray-900">{{ __('admin.jiey_flow_import.section.new_job') }}</h2>
                <p class="mt-2 text-sm text-gray-600">{{ __('admin.jiey_flow_import.section.new_job_desc') }}</p>
            </div>

            <div class="p-6 lg:p-8 space-y-7">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label for="project_id" class="block text-sm font-semibold text-gray-800">{{ __('admin.jiey_flow_import.field.project_id') }}</label>
                        <input
                            id="project_id"
                            name="project_id"
                            type="number"
                            min="1"
                            required
                            value="{{ old('project_id') }}"
                            placeholder="{{ __('admin.jiey_flow_import.placeholder.project_id') }}"
                            class="mt-3 block min-h-12 w-full rounded-xl border-gray-300 px-4 text-base shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                        <p class="mt-2 text-sm text-gray-500">{{ __('admin.jiey_flow_import.help.project_id') }}</p>
                        @error('project_id')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="article_type" class="block text-sm font-semibold text-gray-800">{{ __('admin.jiey_flow_import.field.article_type') }}</label>
                        <select id="article_type" name="article_type" class="mt-3 block min-h-12 w-full rounded-xl border-gray-300 px-4 text-base shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="project" @selected(old('article_type', 'project') === 'project')>{{ __('admin.jiey_flow_import.option.article_type_project') }}</option>
                            <option value="jiey_ide" @selected(old('article_type') === 'jiey_ide')>{{ __('admin.jiey_flow_import.option.article_type_jiey_ide') }}</option>
                        </select>
                        <p class="mt-2 text-sm text-gray-500">{{ __('admin.jiey_flow_import.help.article_type') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label for="project_name" class="block text-sm font-medium text-gray-700">{{ __('admin.jiey_flow_import.field.project_name') }}</label>
                        <input id="project_name" name="project_name" value="{{ old('project_name') }}" placeholder="{{ __('admin.jiey_flow_import.placeholder.project_name') }}" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                    <div>
                        <label for="article_count" class="block text-sm font-medium text-gray-700">{{ __('admin.jiey_flow_import.field.article_count') }}</label>
                        <input id="article_count" name="article_count" type="number" min="1" max="50" value="{{ old('article_count', 10) }}" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                </div>

                <div>
                    <label for="project_description" class="block text-sm font-medium text-gray-700">{{ __('admin.jiey_flow_import.field.project_description') }}</label>
                    <textarea id="project_description" name="project_description" rows="3" placeholder="{{ __('admin.jiey_flow_import.placeholder.project_description') }}" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">{{ old('project_description') }}</textarea>
                </div>

                <div class="rounded-2xl border border-violet-100 bg-violet-50/40 p-5 space-y-5">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.jiey_flow_import.section.generation_options') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ __('admin.jiey_flow_import.section.generation_options_desc') }}</p>
                    </div>

                    <div>
                        <label for="prompt_id" class="block text-sm font-semibold text-gray-800">{{ __('admin.jiey_flow_import.field.prompt_id') }}</label>
                        <select id="prompt_id" name="prompt_id" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500 sm:text-sm">
                            <option value="0">{{ __('admin.jiey_flow_import.option.prompt_auto') }}</option>
                            @if (! empty($rolePromptOptions))
                                <optgroup label="{{ __('admin.jiey_flow_import.option.prompt_role_group') }}">
                                    @foreach ($rolePromptOptions as $prompt)
                                        <option value="{{ (int) $prompt['id'] }}" @selected((int) old('prompt_id', $defaultPromptId ?? 0) === (int) $prompt['id'])>
                                            {{ $prompt['name'] }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @php
                                $roleIds = collect($rolePromptOptions ?? [])->pluck('id')->all();
                                $otherPrompts = collect($promptOptions ?? [])->reject(fn ($p) => in_array((int) $p['id'], array_map('intval', $roleIds), true));
                            @endphp
                            @if ($otherPrompts->isNotEmpty())
                                <optgroup label="{{ __('admin.jiey_flow_import.option.prompt_other_group') }}">
                                    @foreach ($otherPrompts as $prompt)
                                        <option value="{{ (int) $prompt['id'] }}" @selected((int) old('prompt_id', $defaultPromptId ?? 0) === (int) $prompt['id'])>
                                            {{ $prompt['name'] }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        <p class="mt-2 text-sm text-gray-500">{{ __('admin.jiey_flow_import.help.prompt_id') }}</p>
                        @error('prompt_id')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <label class="block text-sm font-semibold text-gray-800">{{ __('admin.jiey_flow_import.field.extra_knowledge_bases') }}</label>
                            <span class="text-xs text-gray-500">{{ __('admin.jiey_flow_import.help.extra_knowledge_bases_limit') }}</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.jiey_flow_import.help.extra_knowledge_bases') }}</p>
                        <div class="mt-3 max-h-56 overflow-y-auto rounded-xl border border-gray-200 bg-white p-3 space-y-2">
                            @forelse (($knowledgeBaseOptions ?? []) as $kb)
                                <label class="flex items-start gap-3 rounded-lg px-2 py-1.5 hover:bg-gray-50">
                                    <input
                                        type="checkbox"
                                        name="extra_knowledge_base_ids[]"
                                        value="{{ (int) $kb['id'] }}"
                                        class="mt-1 rounded border-gray-300 text-violet-600 focus:ring-violet-500"
                                        @checked(collect(old('extra_knowledge_base_ids', []))->map(fn ($id) => (int) $id)->contains((int) $kb['id']))
                                    >
                                    <span class="text-sm text-gray-800">
                                        <span class="font-medium">#{{ (int) $kb['id'] }}</span>
                                        {{ $kb['name'] }}
                                    </span>
                                </label>
                            @empty
                                <p class="px-2 py-3 text-sm text-gray-500">{{ __('admin.jiey_flow_import.section.no_knowledge_bases') }}</p>
                            @endforelse
                        </div>
                        @error('extra_knowledge_base_ids')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @error('extra_knowledge_base_ids.*')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label for="image_library_id" class="block text-sm font-semibold text-gray-800">{{ __('admin.jiey_flow_import.field.image_library_id') }}</label>
                            <select id="image_library_id" name="image_library_id" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500 sm:text-sm">
                                <option value="0">{{ __('admin.jiey_flow_import.option.image_library_none') }}</option>
                                @foreach (($imageLibraryOptions ?? []) as $library)
                                    <option value="{{ (int) $library['id'] }}" @selected((int) old('image_library_id', 0) === (int) $library['id'])>
                                        {{ $library['name'] }}（{{ (int) ($library['count'] ?? 0) }}）
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-2 text-sm text-gray-500">{{ __('admin.jiey_flow_import.help.image_library_id') }}</p>
                            @error('image_library_id')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="image_count" class="block text-sm font-semibold text-gray-800">{{ __('admin.jiey_flow_import.field.image_count') }}</label>
                            <select id="image_count" name="image_count" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500 sm:text-sm">
                                @for ($i = 0; $i <= (int) ($maxArticleImages ?? 10); $i++)
                                    <option value="{{ $i }}" @selected((int) old('image_count', 0) === $i)>
                                        @if ($i === 0)
                                            {{ __('admin.jiey_flow_import.option.image_count_none') }}
                                        @else
                                            {{ __('admin.jiey_flow_import.option.image_count', ['count' => $i]) }}
                                        @endif
                                    </option>
                                @endfor
                            </select>
                            <p class="mt-2 text-sm text-gray-500">{{ __('admin.jiey_flow_import.help.image_count') }}</p>
                            @error('image_count')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label for="artifact_type_slugs" class="block text-sm font-medium text-gray-700">{{ __('admin.jiey_flow_import.field.artifact_type_slugs') }}</label>
                        <input id="artifact_type_slugs" name="artifact_type_slugs" value="{{ old('artifact_type_slugs') }}" placeholder="{{ __('admin.jiey_flow_import.placeholder.artifact_type_slugs') }}" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <p class="mt-2 text-sm text-gray-500">{{ __('admin.jiey_flow_import.help.artifact_type_slugs') }}</p>
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="include_unpublished" value="1" @checked(old('include_unpublished')) class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            {{ __('admin.jiey_flow_import.field.include_unpublished') }}
                        </label>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                    {{ __('admin.jiey_flow_import.section.pipeline_hint') }}
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 lg:px-8">
                <p class="text-sm text-gray-500">{{ __('admin.jiey_flow_import.section.submit_hint') }}</p>
                <button type="submit" @disabled(! $jieyReady || ! $aiModelReady) class="inline-flex items-center rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                    <i data-lucide="download-cloud" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.jiey_flow_import.button.start') }}
                </button>
            </div>
        </form>

        @if ($recentJobs->isNotEmpty())
            <div class="bg-white shadow rounded-2xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.jiey_flow_import.section.recent') }}</h3>
                    <a href="{{ route('admin.jiey-flow-import.history') }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ __('admin.jiey_flow_import.button.view_history') }}</a>
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach ($recentJobs as $job)
                        @php
                            $input = is_array($job->input_data) ? $job->input_data : [];
                            $projectId = (int) ($input['project_id'] ?? 0);
                            $projectName = (string) ($input['project_name'] ?? '');
                        @endphp
                        <a href="{{ route('admin.jiey-flow-import.show', ['jobId' => $job->id]) }}" class="flex items-center justify-between gap-4 px-6 py-4 hover:bg-gray-50">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-gray-900 truncate">
                                    #{{ $job->id }} · project {{ $projectId }}@if($projectName !== '') · {{ $projectName }}@endif
                                </div>
                                <div class="mt-1 text-xs text-gray-500">{{ optional($job->created_at)->format('Y-m-d H:i') }} · {{ $job->current_step }}</div>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-medium
                                @if($job->status === 'completed') bg-emerald-50 text-emerald-700
                                @elseif($job->status === 'failed') bg-red-50 text-red-700
                                @elseif($job->status === 'processing') bg-blue-50 text-blue-700
                                @else bg-gray-100 text-gray-600 @endif">
                                {{ __('admin.jiey_flow_import.status.'.$job->status) }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endsection
