<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">

<label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <input type="hidden" name="{{$name}}"/>

        <select class="form-control {{$class}}" style="width: 100%;" name="{{$name}}" {!! $attributes !!} >
            @php($selectedValue = old($column, $value))
            @if($groups)
                @if($selectedValue === null)
                    <option value=""></option>
                @endif
                @foreach($groups as $group)
                    <optgroup label="{{ $group['label'] }}">
                        @foreach($group['options'] as $select => $option)
                            <option value="{{$select}}" {{ $selectedValue !== null && $select == $selectedValue ?'selected':'' }}>{{$option}}</option>
                        @endforeach
                    </optgroup>
                @endforeach
             @else
                <option value=""></option>
                @foreach($options as $select => $option)
                    <option value="{{$select}}" {{ $selectedValue !== null && $select == $selectedValue ?'selected':'' }}>{{$option}}</option>
                @endforeach
            @endif
        </select>

        @include('admin::form.help-block')

    </div>
</div>
