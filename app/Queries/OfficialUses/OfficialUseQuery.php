<?php

namespace App\Queries\OfficialUses;

use App\Models\Facility;
use App\Models\OfficialUse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class OfficialUseQuery
{
    public static function forRequest(Request $request): Builder
    {
        $query = OfficialUse::query()->inBarangayFor($request->user())->with('facility');
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('purpose', 'like', '%'.$search.'%')->orWhereHas('facility', fn ($f) => $f->where('name', 'like', '%'.$search.'%'));
                if (ctype_digit(ltrim($search, '#'))) {
                    $q->orWhere('id', (int) ltrim($search, '#'));
                }
            });
        }
        $query->when($request->filled('facility_id'), fn ($q) => $q->where('facility_id', $request->integer('facility_id')))
            ->when(in_array($request->query('status'), ['active', 'conflict'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('date', '>=', $request->query('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('date', '<=', $request->query('to_date')));
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $sort = $request->query('sort', 'schedule');
        if ($sort === 'resource') {
            $query->orderBy(Facility::select('name')->whereColumn('facilities.id', 'official_uses.facility_id'), $direction);
        } else {
            $query->orderBy(['id' => 'id', 'schedule' => 'date', 'purpose' => 'purpose', 'status' => 'status'][$sort], $direction);
        }
        if ($sort === 'schedule') {
            $query->orderBy('start_time', $direction);
        }

        return $query->orderBy('id', $direction);
    }
}
