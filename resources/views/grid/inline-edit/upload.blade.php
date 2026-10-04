<a href='javascript:void(0);' class='text-muted inline-upload-trigger' data-target="{{ $target }}">
    <i class="fa fa-upload"></i>&nbsp;{!! $value !!}
</a>
<div class="hide">
  <input type="file" class="inline-upload" id="{{ $target }}" data-key="{{ $key }}" {{ $multiple ? 'multiple' : '' }}/>
</div>

<script>
$('.inline-upload-trigger[data-target="{{ $target }}"]').off('click.adminInlineUpload').on('click.adminInlineUpload', function () {
    $('#'+$(this).data('target')).trigger('click');
});

$('#{{ $target }}').off('change.adminInlineUpload').on('change.adminInlineUpload', function (event) {

    var formData = new FormData();

    @if ($multiple)
        event.target.files.forEach(function (file) {
            formData.append("{{ $name }}[]", file);
        });
    @else
    formData.append("{{ $name }}", event.target.files[0]);
    @endif
    formData.append('_token', LA.token);
    formData.append('_method', 'PUT');

    $.ajax({
        url: "{{ $resource }}/" + $(this).data('key'),
        type: "POST",
        processData: false,
        contentType: false,
        enctype: 'multipart/form-data',
        data: formData,
        success: function (data) {
            toastr.success(data.message);
            $.admin.reload();
        }
    });
});
</script>
