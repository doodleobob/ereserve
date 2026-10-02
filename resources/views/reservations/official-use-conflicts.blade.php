@foreach($reservation->officialUseConflicts as $conflict)
    @if($conflict->resolution !== 'resolved' && $reservation->status === 'accepted')
        <div class="official-use-conflict">
            <p><strong>Conflict: Official Use</strong> #{{ $conflict->official_use_id }}</p>
            <p>Resolution: {{ $conflict->resolutionLabel() }}</p>
            <p>{{ $conflict->officialUse->period()->start->format('M j, Y g:i A') }} – {{ $conflict->officialUse->period()->end->format('M j, Y g:i A') }}</p>
            @unless($isAdmin)
                <p>Choose your preference. The barangay administrator will process the change.</p>
                <form method="POST" action="{{ route('official-use-conflicts.decision', $conflict) }}">
                    @csrf
                    <button type="submit" name="decision" value="reschedule" class="reservation-action-button">Request Reschedule</button>
                    <button type="submit" name="decision" value="cancel" class="reservation-action-button reservation-reject-button">Request Cancellation</button>
                </form>
            @endunless
        </div>
    @endif
@endforeach
