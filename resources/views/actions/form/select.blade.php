<div class="form-group">
    <label>{{ $label }}</label>

    <select class="form-control {{$class}}" style="width: 100%;" name="{{$name}}" {!! $attributes !!} >

        @php($selectedValue = old($column, $value))
        <option value=""></option>
        @foreach($options as $select => $option)
            <option value="{{$select}}" {{ $selectedValue !== null && $select == $selectedValue ?'selected':'' }}>{{$option}}</option>
        @endforeach
    </select>
    @include('admin::actions.form.help-block')
</div>

