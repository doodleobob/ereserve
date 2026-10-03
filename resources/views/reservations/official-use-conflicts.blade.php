@foreach($reservation->officialUseConflicts as $conflict)
    @if($conflict->resolution !== 'resolved' && $reservation->status === 'accepted')
        <div class="official-use-conflict">
            <p><strong>Conflict: Official Use</strong> #{{ $conflict->official_use_id }}</p>
            <p>Resolution: {{ $conflict->resolutionLabel() }}</p>
            <p>{{ $conflict->officialUse->period()->start->format('M j, Y g:i A') }} – {{ $conflict->officialUse->period()->end->format('M j, Y g:i A') }}</p>
            @unless($isAdmin)
                <p>Choose your preference. The barangay administrator will process the change.</p>
                @foreach(['reschedule' => 'Request Reschedule', 'cancel' => 'Request Cancellation'] as $decision => $label)
                    <button type="button" class="reservation-action-button" data-modal-open="conflict-{{ $conflict->id }}-{{ $decision }}">{{ $label }}</button>
                    <x-modal id="conflict-{{ $conflict->id }}-{{ $decision }}" title="{{ $label }}" size="small">
                        <form method="POST" action="{{ route('official-use-conflicts.decision', $conflict) }}" data-modal-action>
                            @csrf
                            <input type="hidden" name="decision" value="{{ $decision }}">
                            <div class="facility-modal-body"><p>{{ $label }} for {{ $reservation->facility_name }}?</p><p>The barangay administrator will process your request.</p><p data-action-error role="alert" hidden></p></div>
                            <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button><button type="submit" class="facility-modal-primary">Confirm Request</button></div>
                        </form>
                    </x-modal>
                @endforeach
            @endunless
        </div>
    @endif
@endforeach
