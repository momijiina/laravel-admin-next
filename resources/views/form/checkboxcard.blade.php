<div class="{{$viewClass['form-group']}} {!! !$errors->has($column) ?: 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}" id="{{$id}}">

        @include('admin::form.error')

        @php
            $selected = array_filter(old($column, $value ?? []), function ($item) {
                return $item === 0 || $item === '0' || (bool) $item;
            });
        @endphp

        <div class="card-group checkbox-group-toggle">
        @foreach($options as $option => $label)
            @php($isChecked = false !== array_search($option, $selected) || ($value === null && in_array($option, $checked)))
            <label class="panel panel-default {{ $isChecked ?'active':'' }}">
                <div class="panel-body">
                <input type="checkbox" name="{{$name}}[]" value="{{$option}}" class="hide {{$class}}" {{ $isChecked ?'checked':'' }} {!! $attributes !!} />&nbsp;{{$label}}&nbsp;&nbsp;
                </div>
            </label>
        @endforeach
        </div>

        <input type="hidden" name="{{$name}}[]">

        @include('admin::form.help-block')

    </div>
</div>
