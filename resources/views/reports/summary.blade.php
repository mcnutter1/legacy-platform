<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $manifest['title'] }}</title>
<style>
  body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 10px; color: #1f2933; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  h2 { font-size: 12px; margin: 18px 0 6px; text-transform: capitalize; border-bottom: 1px solid #cbd2d9; padding-bottom: 3px; }
  .meta { color: #52606d; margin-bottom: 12px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 3px 4px; border-bottom: 1px solid #e4e7eb; vertical-align: top; }
  th { background: #f5f7fa; font-weight: bold; }
  .note { margin-top: 14px; color: #52606d; font-size: 9px; }
</style>
</head>
<body>
<h1>{{ $manifest['title'] }}</h1>
<div class="meta">
  {{ $manifest['workspace'] }} · As of {{ $manifest['as_of'] }} · {{ $manifest['currency'] }} · Generated {{ $manifest['generated_at'] }}
  @if(!empty($manifest['redactions'])) · Redacted: {{ implode(', ', $manifest['redactions']) }} @endif
</div>
@if(!empty($manifest['methodology']))<p>{{ $manifest['methodology'] }}</p>@endif
@foreach($tables as $name => $rows)
  <h2>{{ str_replace('_', ' ', $name) }} ({{ count($rows) }})</h2>
  @if(count($rows))
  <table>
    <thead><tr>@foreach(array_keys($rows[0]) as $h)<th>{{ str_replace('_', ' ', $h) }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach(array_slice($rows, 0, 300) as $r)
      <tr>@foreach($r as $v)<td>{{ is_array($v) ? json_encode($v) : $v }}</td>@endforeach</tr>
    @endforeach
    </tbody>
  </table>
  @if(count($rows) > 300)<p class="note">Showing 300 of {{ count($rows) }} rows; see the CSV for all rows.</p>@endif
  @else
  <p class="note">No records.</p>
  @endif
@endforeach
<p class="note">Report {{ $manifest['report_id'] }} · release {{ $manifest['engine_release'] }}. Figures are drawn from records in McNutt Legacy and may require professional review.</p>
</body>
</html>
