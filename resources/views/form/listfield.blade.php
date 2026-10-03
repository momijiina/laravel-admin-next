
@php($listErrorKey = "$errorKey.values")

<div data-admin-collection="list" @if($collectionReadonly) data-collection-locked @endif class="{{$viewClass['form-group']}} {{ $errors->has($listErrorKey) ? 'has-error' : '' }}">

    <label class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @if($errors->has($listErrorKey))
            @foreach($errors->get($listErrorKey) as $message)
                <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
            @endforeach
        @endif

        {{-- Keep empty defaults before real rows so PHP replaces them with submitted arrays. --}}
        <input type="hidden" name="{{ $name }}[values]" value="" />

        <table class="table table-hover">

            <tbody data-collection-body class="list-{{$column}}-table">

            @php($rows = old("{$errorKey}.values", ($value ?: [])))
            @php($rows = ($rows === null || $rows === '') ? [] : $rows)

            @foreach($rows as $k => $v)

                @php($itemErrorKey = "{$errorKey}.values.{$loop->index}")

                <tr>
                    <td>
                        <div class="form-group {{ $errors->has($itemErrorKey) ? 'has-error' : '' }}">
                            <div class="col-sm-12">
                                <input @if($collectionReadonly) readonly @endif name="{{ $name }}[values][]" value="{{ old("{$errorKey}.values.{$k}", $v) }}" class="form-control" />
                                @if($errors->has($itemErrorKey))
                                    @foreach($errors->get($itemErrorKey) as $message)
                                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    </td>

                    <td style="width: 75px;">
                        <div data-collection-remove @if($collectionReadonly) aria-disabled="true" @endif class="@if($collectionReadonly) disabled @endif {{$column}}-remove btn btn-warning btn-sm pull-right">
                            <i class="fa fa-trash">&nbsp;</i>{{ __('admin.remove') }}
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td>
                        <div data-collection-add @if($collectionReadonly) aria-disabled="true" @endif class="@if($collectionReadonly) disabled @endif {{ $column }}-add btn btn-success btn-sm pull-right">
                            <i class="fa fa-save"></i>&nbsp;{{ __('admin.new') }}
                        </div>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <template class="{{$column}}-tpl">
        <tr>
            <td>
                <div class="form-group">
                    <div class="col-sm-12">
                        <input @if($collectionReadonly) readonly @endif name="{{ $name }}[values][]" class="form-control" />
                    </div>
                </div>
            </td>

            <td style="width: 75px;">
                <div data-collection-remove @if($collectionReadonly) aria-disabled="true" @endif class="@if($collectionReadonly) disabled @endif {{$column}}-remove btn btn-warning btn-sm pull-right">
                    <i class="fa fa-trash">&nbsp;</i>{{ __('admin.remove') }}
                </div>
            </td>
        </tr>
    </template>
</div>
