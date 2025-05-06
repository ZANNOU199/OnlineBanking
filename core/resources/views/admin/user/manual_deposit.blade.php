@extends('admin')
@section('title', 'Manual Deposit')
@section('DEPOSITS', 'active')

@section('content')
<main class="app-content">
    <div class="app-title">
        <div>
            <h1><i class="fa fa-plus-circle"></i> Manual Deposit</h1>
        </div>
    </div>

    <div class="tile">
        <form method="POST" action="{{ route('admin.manual.deposit.submit') }}">
            @csrf

            <div class="form-group">
                <label for="user_id">Select User</label>
                <select name="user_id" id="user_id" class="form-control" required>
                    <option value="" disabled selected>-- Choose User --</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->username }} ({{ $user->email }})</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="amount">Amount ({{ $gnl->cur }})</label>
                <input type="number" name="amount" class="form-control" min="1" required>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa fa-check"></i> Deposit Funds
            </button>
        </form>
    </div>
</main>
@endsection
