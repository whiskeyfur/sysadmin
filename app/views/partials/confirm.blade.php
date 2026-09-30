{{-- Prove it's you with any method you have. Needs $usable (LoginMethodService::usable()), $passkeysHere,
     $formId and $action; $what says what it's for. The passkey button checks first, then submits the form. --}}
<form method="post" action="{{ $action }}" id="{{ $formId }}">
    @csrf
    @include('partials.confirm-fields')
    @if (array_intersect(['password', 'authenticator'], $usable))
        <div class="actions">
            <button type="submit" class="secondary">{{ $what }}</button>
        </div>
    @endif
</form>
