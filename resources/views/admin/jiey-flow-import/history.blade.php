@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0 space-y-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.jiey-flow-import') }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.jiey_flow_import.history_heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.jiey_flow_import.history_subtitle') }}</p>
                </div>
            </div>
            <a href="{{ route('admin.jiey-flow-import') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                {{ __('admin.jiey_flow_import.button.new_job') }}
            </a>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            @foreach (['total', 'queued', 'processing', 'completed', 'failed'] as $statKey)
                <div class="bg-white shadow rounded-lg p-4">
                    <div class="text-sm text-gray-500">{{ __('admin.jiey_flow_import.stats.'.$statKey) }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ (int) ($stats[$statKey] ?? 0) }}</div>
                </div>
            @endforeach
        </div>

        <div class="bg-white shadow rounded-2xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">{{ __('admin.jiey_flow_import.section.records') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.jiey_flow_import.field.project_id') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.jiey_flow_import.field.article_type') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.jiey_flow_import.section.progress') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.jiey_flow_import.section.status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.jiey_flow_import.section.created_at') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @forelse ($jobs as $job)
                            @php
                                $input = is_array($job->input_data) ? $job->input_data : [];
                                $projectId = (int) ($input['project_id'] ?? 0);
                                $projectName = (string) ($input['project_name'] ?? '');
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm">
                                    <a href="{{ route('admin.jiey-flow-import.show', ['jobId' => $job->id]) }}" class="font-medium text-blue-600 hover:text-blue-800">#{{ $job->id }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ $projectId }}
                                    @if ($projectName !== '')
                                        <div class="text-xs text-gray-500">{{ $projectName }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ $job->article_type }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <div>{{ (int) $job->progress_percent }}%</div>
                                    <div class="text-xs text-gray-400">{{ $job->current_step }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium
                                        @if($job->status === 'completed') bg-emerald-50 text-emerald-700
                                        @elseif($job->status === 'failed') bg-red-50 text-red-700
                                        @elseif($job->status === 'processing') bg-blue-50 text-blue-700
                                        @else bg-gray-100 text-gray-600 @endif">
                                        {{ __('admin.jiey_flow_import.status.'.$job->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ optional($job->created_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.jiey_flow_import.section.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($jobs->hasPages())
                <div class="border-t border-gray-200 px-6 py-4">
                    {{ $jobs->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
