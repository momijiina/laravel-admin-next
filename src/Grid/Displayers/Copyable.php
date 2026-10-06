<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Facades\Admin;

/**
 * Class Copyable.
 *
 * @see https://codepen.io/shaikmaqsood/pen/XmydxJ
 */
class Copyable extends AbstractDisplayer
{
    protected function addScript()
    {
        $script = <<<SCRIPT
$('#{$this->grid->tableID}').on('click','.grid-column-copyable',(function (e) {
    var content = JSON.parse($(this).attr('data-content'));
    
    var temp = $('<span contenteditable="true"></span>').css({
        position: 'fixed', left: '-9999px', whiteSpace: 'pre'
    });

    $("body").append(temp);
    temp.text(content);
    var range = document.createRange();
    range.selectNodeContents(temp[0]);
    var selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    document.execCommand("copy");
    temp.remove();
    selection.removeAllRanges();
    
    $(this).tooltip('show');
}));
SCRIPT;

        Admin::script($script);
    }

    public function display()
    {
        $this->addScript();

        // JSON retains literal newlines through the Grid and browser HTML parsers.
        $content = e(json_encode((string) $this->getColumn()->getOriginal(), JSON_INVALID_UTF8_SUBSTITUTE));

        return <<<HTML
<a href="javascript:void(0);" class="grid-column-copyable text-muted" data-content="{$content}" title="Copied!" data-placement="bottom">
    <i class="fa fa-copy"></i>
</a>&nbsp;{$this->getValue()}
HTML;
    }
}
