<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>eReserve Payment Report</title>
<style>
@page {margin:30px 28px 42px} body {font-family:DejaVu Sans,sans-serif;font-size:11px;color:#1e293b} h1 {font-size:22px;margin:0;color:#2442ba} h2 {font-size:15px;margin:5px 0 16px} .metadata {margin-bottom:18px;line-height:1.7} table {width:100%;border-collapse:collapse;table-layout:fixed} thead {display:table-header-group} th,td {border:1px solid #cbd5e1;padding:7px;word-wrap:break-word;vertical-align:top} th {background:#eef2ff;text-align:left} tr {page-break-inside:avoid} .total {text-align:right} .summary {margin-top:18px;font-size:11px} .muted {color:#64748b}
</style></head><body>
<h1>eReserve</h1><h2>Payment Report</h2>
<div class="metadata">@foreach($metadata as $label=>$value)<strong>{{ $label }}:</strong> {{ $value }}<br>@endforeach</div>
<table><thead><tr>@foreach(\App\Support\PaymentReport::COLUMNS as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr>@foreach($row as $index=>$value)<td @if($index===5) class="total" @endif>{{ $index===5 ? \App\Support\Money::format($value) : $value }}</td>@endforeach</tr>
@empty<tr><td colspan="7">No payment records found.</td></tr>@endforelse
</tbody></table>
<div class="summary"><strong>Total Payments:</strong> {{ number_format($summary['count']) }}<br><strong>Total:</strong> {{ \App\Support\Money::format($summary['total']) }}</div>
@if($summary['unrecorded'])<p class="muted">{{ $summary['unrecorded'] }} reservation total(s) not recorded; excluded from Total.</p>@endif
</body></html>
