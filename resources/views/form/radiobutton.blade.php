<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        @php($selectedValue = old($column, $value))

        <div class="btn-group radio-group-toggle">
            @foreach($options as $option => $label)
                @php($isChecked = ($selectedValue !== null && $option == $selectedValue) || ($value === null && in_array($label, $checked)))
                <label class="btn btn-default {{ $isChecked ?'active':'' }}">
                    <input type="radio" name="{{$name}}" value="{{$option}}" class="hide minimal {{$class}}" {{ $isChecked ?'checked':'' }} {!! $attributes !!} />&nbsp;{{$label}}&nbsp;&nbsp;
                </label>
            @endforeach
        </div>

        @include('admin::form.help-block')

    </div>
</div>
