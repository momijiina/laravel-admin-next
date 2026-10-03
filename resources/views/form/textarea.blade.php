<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        {{-- HTML consumes the first LF after <textarea>; keep this newline before the value. --}}
        <textarea name="{{$name}}" class="form-control {{$class}}" rows="{{ $rows }}" placeholder="{{ $placeholder }}" {!! $attributes !!} >
{{ old($column, $value) }}</textarea>

        {!! $append !!}

        @include('admin::form.help-block')

    </div>
</div>
