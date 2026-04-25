<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Snapshot Details – DB Optimizer</title>
	<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto px-6 py-8">

	{{-- Header --}}
	<a href="{{ route('db-optimizer.index') }}" class="text-blue-300 text-sm hover:underline">← Back to snapshots</a>
	<h1 class="text-2xl font-semibold mt-2">Snapshot Details</h1>
	<p class="text-slate-400 mb-6 text-sm">
		{{ data_get($snapshot, 'captured_at', '-') }}
		&middot;
		<span class="font-mono text-slate-300">{{ data_get($snapshot, 'meta.method', 'GET') }} {{ data_get($snapshot, 'meta.route', '-') }}</span>
	</p>

	@php
		$queries = data_get($snapshot, 'queries', []);
	@endphp

	@if(empty($queries))
		<div class="rounded-xl border border-slate-800 bg-slate-900 p-6 text-slate-400">No queries recorded in this snapshot.</div>
	@else
		<div class="space-y-6">
		@foreach($queries as $qi => $query)

			@php
				$isNPlusOne     = data_get($query, 'detectors.n_plus_one.is_suspected', false);
				$repetitions    = data_get($query, 'detectors.n_plus_one.repetition', 1);
				$missingIndexes = data_get($query, 'detectors.missing_indexes', []);
				$isSlow         = isset($query['explain']);
				$recommendations = data_get($query, 'recommendations', []);

				// Badge colour
				$borderColor = 'border-slate-700';
				if ($isNPlusOne)    $borderColor = 'border-red-600';
				elseif ($isSlow)    $borderColor = 'border-amber-500';
				elseif (!empty($missingIndexes)) $borderColor = 'border-yellow-500';
			@endphp

			<div class="rounded-xl border {{ $borderColor }} bg-slate-900 overflow-hidden">

				{{-- ── Query Header ─────────────────────────────────────── --}}
				<div class="px-5 py-3 bg-slate-800/60 flex flex-wrap items-center gap-3 text-xs">
					<span class="text-slate-300 font-mono">Query #{{ $qi + 1 }}</span>
					<span class="text-slate-400">{{ $query['time_ms'] ?? 0 }} ms</span>

					@if($isNPlusOne)
						<span class="bg-red-600/20 text-red-300 border border-red-600/40 rounded px-2 py-0.5">N+1 &times;{{ $repetitions }}</span>
					@endif
					@if($isSlow)
						<span class="bg-amber-500/20 text-amber-300 border border-amber-500/40 rounded px-2 py-0.5">Slow Query</span>
					@endif
					@if(!empty($missingIndexes))
						<span class="bg-yellow-500/20 text-yellow-300 border border-yellow-500/40 rounded px-2 py-0.5">Missing Index</span>
					@endif

					@if(data_get($query, 'origin.file'))
						<span class="ml-auto text-slate-500 truncate">
							{{ data_get($query, 'origin.file') }}:{{ data_get($query, 'origin.line') }}
						</span>
					@endif
				</div>

				<div class="p-5 space-y-4">

					{{-- ── Detected (Actual) SQL ───────────────────────── --}}
					<div>
						<div class="text-xs text-sky-400 font-medium mb-1">
							@if($isNPlusOne)
								⚠ Repeated Query (fired {{ $repetitions }}× in this request)
							@else
								Detected Query
							@endif
						</div>
						<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-950 border border-slate-800 rounded-lg p-3 leading-relaxed">{{ $query['raw_sql'] ?? $query['sql'] ?? '' }}</pre>
					</div>

					{{-- ── EXPLAIN summary for slow queries ───────────── --}}
					@if($isSlow && data_get($query, 'explain.summary'))
						<div class="rounded-lg bg-amber-950/40 border border-amber-700/30 px-4 py-3 text-xs text-amber-200">
							<span class="font-semibold">EXPLAIN:</span> {{ data_get($query, 'explain.summary') }}
						</div>
					@endif

					{{-- ── Missing index hints ─────────────────────────── --}}
					@if(!empty($missingIndexes))
						<div class="rounded-lg bg-yellow-950/30 border border-yellow-700/30 px-4 py-3 text-xs text-yellow-200 space-y-1">
							<div class="font-semibold mb-1">Missing Indexes Detected:</div>
							@foreach($missingIndexes as $idx)
								<div>&bull; <code class="font-mono">`{{ $idx['table'] }}`.`{{ $idx['column'] }}`</code> — {{ $idx['reason'] ?? '' }}</div>
							@endforeach
						</div>
					@endif

					{{-- ── Recommendations ─────────────────────────────── --}}
					@if(!empty($recommendations))
						<div class="space-y-4">
							<div class="text-xs font-semibold text-slate-300 uppercase tracking-wide">Optimization Suggestions</div>

							@foreach($recommendations as $rec)
								<div class="rounded-xl border border-slate-700 bg-slate-950/80 p-4 space-y-3">

									{{-- Title + description --}}
									<div>
										<div class="text-sm font-semibold text-white">{{ $rec['title'] ?? 'Recommendation' }}</div>
										<div class="text-xs text-slate-400 mt-1">{{ $rec['description'] ?? '' }}</div>
									</div>

									@php
										$hasBothLaravel = !empty($rec['current_laravel']) && !empty($rec['optimized_laravel']);
										$currentDiffersFromOptimized = $rec['current_laravel'] !== $rec['optimized_laravel'];
									@endphp

									{{-- ── Current Laravel Code ─────────── --}}
									@if(!empty($rec['current_laravel']) && $hasBothLaravel && $currentDiffersFromOptimized)
										<div>
											<div class="text-xs font-medium text-sky-400 mb-1">📄 Current Code (Problem)</div>
											<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-900 border border-sky-900/50 rounded-lg p-3 leading-relaxed text-sky-100">{{ $rec['current_laravel'] }}</pre>
										</div>
									@endif

									{{-- ── Optimized Laravel Code ──────── --}}
									@if(!empty($rec['optimized_laravel']))
										<div>
											<div class="text-xs font-medium text-emerald-400 mb-1">✅ Optimized Code (Fix)</div>
											<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-900 border border-emerald-900/50 rounded-lg p-3 leading-relaxed text-emerald-100">{{ $rec['optimized_laravel'] }}</pre>
										</div>
									@endif

									{{-- ── Optimized SQL ──────────────── --}}
									@if(!empty($rec['optimized_sql']) && $rec['optimized_sql'] !== $rec['current_sql'])
										<div>
											<div class="text-xs font-medium text-purple-400 mb-1">🔧 Optimized SQL</div>
											<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-900 border border-purple-900/50 rounded-lg p-3 leading-relaxed text-purple-100">{{ $rec['optimized_sql'] }}</pre>
										</div>
									@endif

									{{-- ── Executable SQL (safe/guarded) ─ --}}
									@if(!empty($rec['executable_sql']))
										<div>
											<div class="text-xs font-medium text-amber-400 mb-1">⚡ Executable SQL (Safe / Guarded)</div>
											<pre class="text-xs overflow-x-auto whitespace-pre-wrap bg-slate-900 border border-amber-900/50 rounded-lg p-3 leading-relaxed text-amber-100">{{ $rec['executable_sql'] }}</pre>
										</div>
									@endif

								</div>
							@endforeach
						</div>
					@endif

				</div>
			</div>

		@endforeach
		</div>
	@endif

</div>
</body>
</html>
