{{-- An error alert; for the refused-login wait, admins get a button on the right to clear it (their own). --}}
@if ($message === \App\Services\MariadbQueryService::THROTTLED && $auth->isAdmin())
    <div class="alert error alert-action" role="alert">
        <span>{{ $message }}</span>
        <form method="post" action="/mariadb/query/unthrottle">
            @csrf
            <button type="submit" class="secondary">Clear the wait</button>
        </form>
    </div>
@else
    <div class="alert error" role="alert">{{ $message }}</div>
@endif
