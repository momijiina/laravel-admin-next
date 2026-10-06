<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        @php($selectedValue = old($column, $value))

        <div class="card-group radio-group-toggle">
            @foreach($options as $option => $label)
                @php($isChecked = ($selectedValue !== null && $option == $selectedValue) || ($value === null && in_array($label, $checked)))
                <label class="panel panel-default {{ $isChecked ?'active':'' }}">
                    <div class="panel-body">
                    <input type="radio" name="{{$name}}" value="{{$option}}" class="hide minimal {{$class}}" {{ $isChecked ?'checked':'' }} {!! $attributes !!} />&nbsp;{{$label}}&nbsp;&nbsp;
                    </div>
                </label>
            @endforeach
        </div>

        @include('admin::form.help-block')

    </div>
</div>
