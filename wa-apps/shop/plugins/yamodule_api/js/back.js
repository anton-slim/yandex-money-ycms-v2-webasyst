function hideMethods()
{
    var inside = $('#ya_kassa_inside:checked').val();
    if (inside == 1) {
        $('.kassa_methods').slideDown('slow');
        $('.text_inside').slideUp('slow');
    } else {
        $('.kassa_methods').slideUp('slow');
        $('.text_inside').slideDown('slow');
    }
    return inside;
}

$(document).ready(function() {
    hideMethods();
    $('input#ya_kassa_inside').on('change', function(){
        hideMethods();
    });

    var view = $.totalStorage('tab_ya');
    if (view == null) {
        $.totalStorage('tab_ya', 'money');
    } else {
        $('.tabs_ya > label[for="' + view + '"]').trigger('click');
        var el = document.getElementById('yabilling_view');
        if (view == 'yabilling') {
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    }

    $('.tabs_ya > label').live('click', function(){
        var view = this.getAttribute('for');
        var el = document.getElementById('yabilling_view');
        if (view == 'yabilling') {
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
        $.totalStorage('tab_ya', this.getAttribute('for'));
    });

    $('.save_data').live('click', function(e){
        e.preventDefault();
        $form = $(this).parents('form').first();
        var data = $form.serializeObject();
        data.action = 'kassa';
        $.ajax({
            type: 'POST',
            url: $form.attr('action'),
            data: data,
            dataType: 'Json',
            beforeSend: function(){
                $('#adv-page-loader').show();
                $('.tabs_ya').addClass('adv-blur');
            },
            complete: function (){
                $('#adv-page-loader').hide();
                $('.tabs_ya').removeClass('adv-blur');
            },
            success: function(jsonData){
                if (jsonData.status == 'ok')
                {
                    var errors = jsonData.data.errors;
                    for (type in errors){
                        $('.'+type+'_errors').html('');
                        for (dd in errors[type]){
                            $('.'+type+'_errors').append(errors[type][dd]);
                        }
                    }
                }
                else
                {
                    alert('Ошибка! Повторите.');
                }
            },
        });
    });

});

$.fn.serializeObject = function () {
    var o = {};
    var a = this.serializeArray({ checkboxesAsBools: true});
    $.each(a, function () {
        if (o[this.name]) {
            if (!o[this.name].push) {
                o[this.name] = [o[this.name]];
            }
            o[this.name].push(this.value || '');
        } else {
            o[this.name] = this.value || '';
        }
    });
 
    return o;
};
 
$.fn.serializeArray = function (options) {
    var o = $.extend({
        checkboxesAsBools: false
    }, options || {});
 
    var rselectTextarea = /select|textarea/i;
    var rinput = /text|hidden|password|search/i;
 
    return this.map(function () {
        return this.elements ? $.makeArray(this.elements) : this;
    })
    .filter(function () {
        return this.name && !this.disabled &&
            (this.checked
            || (o.checkboxesAsBools && this.type === 'checkbox')
            || rselectTextarea.test(this.nodeName)
            || rinput.test(this.type));
    })
        .map(function (i, elem) {
            var val = $(this).val();
            return val == null ?
            null :
            $.isArray(val) ?
            $.map(val, function (val, i) {
                return { name: elem.name, value: val };
            }) :
            {
                name: elem.name,
                value: (o.checkboxesAsBools && this.type === 'checkbox') ?
                    ((this.name != 'ya_market_categories[]') ? (this.checked ? 1 : 0) : (this.checked ? val : '')) :
                    val
            };
        }).get();
};