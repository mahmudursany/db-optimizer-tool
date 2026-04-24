<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Snapshot</title>
	<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto px-6 py-8">
	<a href="{{ route('db-optimizer.index') }}" class="text-blue-300 text-sm">← Back</a>
	<h1 class="text-2xl font-semibold mt-2">Snapshot Details</h1>
	<p class="text-slate-400 mb-4">{{ data_get($snapshot,'captured_at','-') }} · {{ data_get($snapshot,'meta.method','GET') }} {{ data_get($snapshot,'meta.route','-') }}</p>

	<div class="space-y-3">
		@foreach(data_get($snapshot,'queries',[]) as $query)
			<div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
				<div class="text-xs text-slate-400 mb-2">{{ $query['time_ms'] ?? 0 }} ms · {{ data_get($query,'origin.file','-') }}:{{ data_get($query,'origin.line','-') }}</div>

				<div class="text-xs text-slate-400 mb-1">Detected Query</div>
				<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-950 border border-slate-800 rounded p-3">{{ $query['raw_sql'] ?? $query['sql'] ?? '' }}</pre>

				@if(isset($query['explain']))
					<div class="text-xs text-amber-200 mt-2">{{ data_get($query,'explain.summary') }}</div>
				@endif

				@if(!empty(data_get($query, 'recommendations', [])))
					<div class="mt-4 space-y-2">
						<div class="text-xs font-medium text-slate-300">Suggested Rewrites</div>
						@foreach(data_get($query, 'recommendations', []) as $recommendation)
							<div class="rounded-lg border border-slate-800 bg-slate-950 p-3">
								<div class="text-xs font-medium text-slate-200">{{ $recommendation['title'] ?? 'Recommendation' }}</div>
								<div class="text-xs text-slate-400 mt-1">{{ $recommendation['description'] ?? '' }}</div>

								@if(!empty($recommendation['current_laravel']))
									<div class="text-xs text-sky-300 mt-2">Current Laravel Query</div>
									<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-900 border border-slate-800 rounded p-3">{{ $recommendation['current_laravel'] }}</pre>
								@endif

								@if(!empty($recommendation['optimized_laravel']))
									<div class="text-xs text-emerald-300 mt-2">Optimized Laravel Query</div>
									<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-900 border border-slate-800 rounded p-3">{{ $recommendation['optimized_laravel'] }}</pre>
								@endif

								@if(!empty($recommendation['optimized_sql']))
									<div class="text-xs text-emerald-300 mt-2">New Query</div>
									<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-900 border border-slate-800 rounded p-3">{{ $recommendation['optimized_sql'] }}</pre>
								@endif

								@if(!empty($recommendation['executable_sql']))
									<div class="text-xs text-amber-300 mt-2">Executable SQL (Safe/Guarded)</div>
									<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-900 border border-slate-800 rounded p-3">{{ $recommendation['executable_sql'] }}</pre>
								@endif
							</div>
						@endforeach
					</div>
				@endif
			</div>
		@endforeach
	</div>
</div>
</body>
</html>
