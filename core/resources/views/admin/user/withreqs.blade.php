@extends('admin')
@section('user', 'active')
@section('title')
    Withdraw request
@stop

@section('content')
    <main class="app-content">

        <div class="raw">
            <div class="col-lg-12">
                <div class="tile">
                    <div class="table-responsive">
                        <table class="table table-hover text-center">
                            <thead>
                            <tr>
                                <th> @lang('Username')</th>
                                <th> @lang('Amount')</th>
                                <th> @lang('Method')</th>
                                <th> @lang('Account')</th>
                                <th> @lang('TRX Time')</th>
                                <th> @lang('Status')</th>
                                <th> @lang('Action')</th>
                            </tr>
                            </thead>
                            <tbody>
                            @if(count($reqs) == 0)
                                <tr>
                                    <td colspan="7"><h2>@lang('No Data Available')</h2></td>
                                </tr>
                            @endif
                            @foreach($reqs as $log)
                                <tr>
                                    <td> <a href="{{ route('admin.userDetails', $log->user->id) }}"> {{$log->user->username}} </a></td>
                                    <td>{{$log->amount}} {{$gnl->cur}}</td>
                                    <td>{{$log->wmethod->name}}</td>
                                    <td>{{$log->account}}</td>
                                    <td>{{$log->created_at->diffForHumans()}}</td>
                                    <td>
                                        @if($log->status == 0)
                                            <span class="badge badge-warning">@lang('Pending')</span>
                                        @elseif($log->status == 1)
                                            <span class="badge badge-success">@lang('Approve')</span>
                                        @elseif($log->status == 2)
                                            <span class="badge badge-danger">@lang('Refund')</span>
                                        @endif
                                    </td>

                                    <td>
                                       <button class="btn btn-success approveButton" data-toggle="modal" data-gate="{{$log->id}}" data-target="#approveModal">
                                           <i class="fa fa-check"></i> Approve
                                       </button>
                                        <button class="btn btn-danger rejectButton" data-toggle="modal" data-gate="{{$log->id}}" data-target="#rejectModal">
                                           <i class="fa fa-times"></i> Reject
                                       </button>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>

                        </table>
                        <div class="d-flex flex-row-reverse">
                            {{$reqs->links()}}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Approve Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ModalLabel">@lang('Are you sure you want to approve this?')</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form action="{{ route('admin.withdraw.approve', ['id' => '']) }}" method="POST">
                        @csrf
                        <input type="hidden" name="withdraw" id="withdrawApprove"/>

                        <div class="modal-footer">
                            <button type="submit" class="btn btn-success">@lang('Approve')</button>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">@lang('Close')</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ModalLabel">@lang('Are you sure you want to reject this?')</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form action="{{ route('admin.withdraw.reject', ['id' => '']) }}" method="POST">
                        @csrf
                        <input type="hidden" name="withdraw" id="withdrawReject"/>

                        <div class="modal-footer">
                            <button type="submit" class="btn btn-danger">@lang('Reject')</button>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">@lang('Close')</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('script')
    <script>
        $(document).ready(function() {
            // Update the approve modal form action dynamically
            $(document).on('click', '.approveButton', function() {
                var withdrawalId = $(this).data('gate');  // Get the withdrawal ID
                $('#withdrawApprove').val(withdrawalId);  // Add ID to the hidden input field
                var formAction = '{{ route('admin.withdraw.approve', ['id' => '']) }}' + '/' + withdrawalId; // Build the action URL
                $('form[action*="withdraw/approve"]').attr('action', formAction); // Update the form action
            });

            // Update the reject modal form action dynamically
            $(document).on('click', '.rejectButton', function() {
                var withdrawalId = $(this).data('gate');  // Get the withdrawal ID
                $('#withdrawReject').val(withdrawalId);  // Add ID to the hidden input field
                var formAction = '{{ route('admin.withdraw.reject', ['id' => '']) }}' + '/' + withdrawalId; // Build the action URL
                $('form[action*="withdraw/reject"]').attr('action', formAction); // Update the form action
            });
        });
    </script>
@endsection
