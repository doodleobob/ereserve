<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnalyticsPeriod
{
    public function __construct(
        public string $period,
        public ?Carbon $start,
        public ?Carbon $end,
    ) {}

    public static function forRequest(Request $request, bool $systemWide = false): self
    {
        // Preserve existing Super Admin bookmarks; new forms use Admin's names.
        $field = $systemWide && ! $request->has('analytics_period') && $request->has('period')
            ? 'period' : 'analytics_period';
        $input = $request->validate([
            $field => ['sometimes', Rule::in($systemWide ? ['7', '30', 'month', 'year', 'custom', 'all'] : ['7', '30', 'month', 'custom'])],
            'analytics_start' => ["exclude_unless:$field,custom", 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'analytics_end' => ["exclude_unless:$field,custom", 'required', 'date_format:Y-m-d', 'after_or_equal:analytics_start', 'before_or_equal:today'],
        ]);
        $period = $input[$field] ?? ($systemWide ? 'year' : '30');
        $end = $period === 'all' ? null : today();
        $start = $period === 'custom'
            ? Carbon::parse($input['analytics_start'])->startOfDay()
            : self::presetStart($period, today());
        if ($period === 'custom') {
            $end = Carbon::parse($input['analytics_end'])->startOfDay();
        }
        if ($start && $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['analytics_end' => 'Choose a range of at most 366 days.']);
        }

        return new self($period, $start, $end);
    }

    private static function presetStart(string $period, Carbon $today): ?Carbon
    {
        return match ($period) {
            '7' => $today->copy()->subDays(6),
            'month' => $today->copy()->startOfMonth(),
            'year' => $today->copy()->startOfYear(),
            'all' => null,
            default => $today->copy()->subDays(29),
        };
    }

    public static function presets(): array
    {
        // Supply the UI with the same application-timezone dates used by queries.
        $today = today();
        $presets = [];
        foreach (['7', '30', 'month', 'year', 'all'] as $period) {
            $presets[$period] = [
                'start' => self::presetStart($period, $today)?->toDateString() ?? '',
                'end' => $period === 'all' ? '' : $today->toDateString(),
            ];
        }

        return $presets;
    }

    public function apply(Builder $query): Builder
    {
        if ($this->start) {
            $query->where('created_at', '>=', $this->start)
                ->where('created_at', '<', $this->end->copy()->addDay());
        }

        return $query;
    }
}
