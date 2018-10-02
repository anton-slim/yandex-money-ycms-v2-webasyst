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

function marketEditOnHandler(event) {
    event.stopPropagation();
    event.preventDefault();
    const parent = $(this).closest('.yandex-money-market-js-editable');
    parent.find('.yandex-money-market-edit-on-button').hide();
    parent.find('.yandex-money-market-js-editable-view').hide();
    parent.find('.yandex-money-market-js-editable-edit').show();
    $(this).hide();
}

function marketJsEditableEditFinishHandler(parent) {
    parent.find('.yandex-money-market-js-editable-edit').hide();
    parent.find('.yandex-money-market-edit-on-button').css('display', '');
    parent.find('.yandex-money-market-js-editable-view').show();
}

function marketCurrencyUpdateViewValues(parent) {
    const plus = parent.find('.yandex-money-market-currency-plus').val();
    const rateOption = parent.find('.yandex-money-market-currency-rate option:selected');
    parent.find('.yandex-money-market-currency-view-plus-value').text(plus);
    parent.find('.yandex-money-market-currency-view-rate').text(rateOption.text());
}

function marketCurrencyEditFinishHandler() {
    const parent = $(this).closest('.yandex-money-market-js-editable');
    marketJsEditableEditFinishHandler(parent);
    marketCurrencyUpdateViewValues(parent);
}

function marketSetPrevValues(elements) {
    elements.each(function () {
        let el = $(this);
        if (el.attr('type') === 'checkbox' || el.attr('type') === 'radio') {
            el.prop('checked', el.val() === el.data('value'));
        } else {
            el.val(el.data('value'));
        }
    });
}

function marketCurrencyEditFinishResetHandler() {
    const parent = $(this).closest('.yandex-money-market-js-editable');
    const elements = parent.find('.yandex-money-market-js-editable-edit').find('select, input[type!=button]');
    marketSetPrevValues(elements);
    marketJsEditableEditFinishHandler(parent);
    marketCurrencyUpdateViewValues(parent);
}

function marketAllCategoriesChangeHandler() {
    if ($(this).val()) {
        $(this).closest('.yandex-money-market-category-tree-container').find('.yandex-money-market-category-tree').slideUp();
    } else {
        $(this).closest('.yandex-money-market-category-tree-container').find('.yandex-money-market-category-tree').slideDown();
    }
}

function marketShowCatAll() {
    $(this).closest('.yandex-money-market-category-tree').find("ul.yandex-money-market-category-tree-branch").each(function () {
        $(this).slideDown();
    });
}

function marketHideCatAll() {
    $(this).closest('.yandex-money-market-category-tree').find("ul.yandex-money-market-category-tree-branch").each(function () {
        $(this).slideUp();
    });
}

function marketCheckAll() {
    $(this).closest('.yandex-money-market-category-tree').find(":input[type=checkbox]").each(function () {
        $(this).prop("checked", true);
    });
}

function marketUncheckAll() {
    $(this).closest('.yandex-money-market-category-tree').find(":input[type=checkbox]").each(function () {
        $(this).prop("checked", false);
    });
}

function marketCategoryClickHandler(){
    $(this).closest('li').find('input[type="checkbox"]').prop('checked', $(this).prop('checked'));
}

function marketDeliveryEditFinishHandler() {
    const parent = $(this).closest('.yandex-money-market-js-editable');
    marketJsEditableEditFinishHandler(parent);
    marketDeliveryUpdateViewValues(parent)
}

function marketDeliveryEditFinishResetHandler() {
    const parent = $(this).closest('.yandex-money-market-js-editable');
    const elements = parent.find('.yandex-money-market-js-editable-edit').find('select, input[type!=button]');
    marketSetPrevValues(elements);
    marketJsEditableEditFinishHandler(parent);
    marketDeliveryUpdateViewValues(parent);
}

function marketDeliveryUpdateViewValues(parent) {
    let edit = parent.find('.yandex-money-market-js-editable-edit');
    let cost = edit.find('.yandex-money-market-delivery-cost').val();
    let daysFrom = edit.find('.yandex-money-market-delivery-days-from').val();
    let daysTo = edit.find('.yandex-money-market-delivery-days-to').val();
    let orderBeforeOption = edit.find('.yandex-money-market-delivery-order-before option:selected');
    let orderBeforeText = +orderBeforeOption.val()
        ? orderBeforeOption.text()
        : '13:00 (по умолчанию для Маркета)';
    let days = !daysTo || daysFrom === daysTo ? +daysFrom : daysFrom + '-' + daysTo;

    let view = parent.find('.yandex-money-market-js-editable-view');
    view.find('.yandex-money-market-delivery-cost').text(+cost);
    view.find('.delivery_days').text(days);
    view.find('.yandex-money-market-delivery-order-before').text(orderBeforeText);
}

function marketOfferFormatClickHandler() {
    if ($('input[name="yandex_money_market_simple"]:checked').val()) {
        $('.yandex-money-market-offer-name-template').show();
    } else {
        $('.yandex-money-market-offer-name-template').hide();
    }
}

function marketAvailableEditFinishHandler() {
    let parent = $(this).closest('.yandex-money-market-js-editable');
    marketJsEditableEditFinishHandler(parent);
    marketAvailableUpdateViewValues(parent)
}

function marketAvailableEditFinishResetHandler() {
    let parent = $(this).closest('.yandex-money-market-js-editable');
    const elements = parent.find('.yandex-money-market-js-editable-edit').find('select, input[type!=button]');
    marketSetPrevValues(elements);
    marketJsEditableEditFinishHandler(parent);
    marketAvailableUpdateViewValues(parent)
}

function marketAvailableUpdateViewValues(parent) {
    let edit = parent.find('.yandex-money-market-js-editable-edit');
    let view = parent.find('.yandex-money-market-js-editable-view');

    let delivery = edit.find('.yandex-money-market-available-delivery').is(':checked');
    let pickup = edit.find('.yandex-money-market-available-pickup').is(':checked');
    let store = edit.find('.yandex-money-market-available-store').is(':checked');

    let available = edit.find('select option:selected').val();
    if (available === 'none') {
        view.find('.available_dont_upload').show();
        view.find('.available_will_upload').hide();
    } else {
        view.find('.available_dont_upload').hide();
        view.find('.available_will_upload').show();
        if (available === 'true') {
            view.find('.yandex-money-market-available-with-ready').show();
            view.find('.yandex-money-market-available-with-to-order').hide();
        } else {
            view.find('.yandex-money-market-available-with-ready').hide();
            view.find('.yandex-money-market-available-with-to-order').show();
        }
        if (delivery || pickup || store) {
            view.find('.yandex-money-market-available-view-available-list').show();
            if (delivery) {
                let el = view.find('.yandex-money-market-available-delivery');
                el.show();
                if (pickup || store) {
                    el.removeClass('last');
                } else {
                    el.addClass('last');
                }
            } else {
                view.find('.yandex-money-market-available-delivery').hide();
            }
            if (pickup) {
                let el = view.find('.yandex-money-market-available-pickup');
                el.show();
                if (store) {
                    el.removeClass('last');
                } else {
                    el.addClass('last');
                }
            } else {
                view.find('.yandex-money-market-available-pickup').hide();
            }
            if (store) {
                view.find('.yandex-money-market-available-store').show();
            } else {
                view.find('.yandex-money-market-available-store').hide();
            }
        } else {
            view.find('.yandex-money-market-available-view-available-list').hide();
        }
    }
}

function marketAddNewAdditionalCondition() {
    let index = $(this).data('index');
    let nextIndex = index + 1;
    $(this).data('index', nextIndex);
    let list = $('.yandex-money-market-additional-condition-list');
    let template = list.find('.yandex-money-market-additional-condition-template');
    let newForm = template.clone();
    newForm.removeClass('yandex-money-market-additional-condition-template');
    template.before(newForm);
    newForm.find('.yandex-money-market-edit-on-button').click();
    newForm.find('select, input[type!=button]').each(function () {
        $(this).attr('name', $(this).data('name').replace(/\[\]/, '[' + index + ']'));
    });
}

function marketAdditionalConditionEditFinishHandler() {
    let parent = $(this).closest('.yandex-money-market-additional-condition');
    marketJsEditableEditFinishHandler(parent);
    marketAdditionalConditionUpdateViewValues(parent)
}

function marketAdditionalConditionEditFinishResetHandler() {
    let parent = $(this).closest('.yandex-money-market-additional-condition');
    const elements = parent.find('.yandex-money-market-js-editable-edit').find('select, input[type!=button]');
    marketSetPrevValues(elements);
    marketJsEditableEditFinishHandler(parent);
    marketAdditionalConditionUpdateViewValues(parent)
}

function marketAdditionalConditionDeleteHandler() {
    $(this).closest('.yandex-money-market-additional-condition').detach();
}

function marketAdditionalConditionUpdateViewValues(parent) {
    let edit = parent.find('.yandex-money-market-js-editable-edit');
    let name = edit.find('input[name^=yandex_money_market_additional_condition_name]').val();
    let tag = edit.find('input[name^=yandex_money_market_additional_condition_tag]').val();
    let typeValue = edit.find('input[name^=yandex_money_market_additional_condition_type_value]:checked').val();
    let staticValue = edit.find('input[name^=yandex_money_market_additional_condition_static_value]').val();
    let dataValueOption = edit.find('select option:selected');
    let forAllCat = edit.find('input[name^=yandex_money_market_additional_condition_for_all_cat]:checked').val();
    let valueText = typeValue === 'static' ? staticValue : dataValueOption.text();

    let view = parent.find('.yandex-money-market-js-editable-view');
    view.find('.yandex-money-market-additional-condition-name').text(name);
    view.find('.yandex-money-market-additional-condition-tag').text(tag);
    view.find('.yandex-money-market-additional-condition-value').text(valueText);
    let forAllCatText = forAllCat  ? 'всех категорий' : 'выбранных категорий';
    view.find('.yandex-money-market-additional-condition-category-list').text(forAllCatText);
}

function marketVatClickHandler() {
    if ($('input[name="yandex_money_market_vat_enabled"]').is(':checked')) {
        $('.yandex-money-market-val-list').show();
    } else {
        $('.yandex-money-market-val-list').hide();
    }
}

function clearSelection() {
    if (window.getSelection) {
        if (window.getSelection().empty) {
            window.getSelection().empty();
        } else if (window.getSelection().removeAllRanges) {
            window.getSelection().removeAllRanges();
        }
    } else if (document.selection) {
        document.selection.empty();
    }
}

function marketCopyUrlToClipboard() {
    let el = $('input[name="ya_market_yml"]');
    el.prop('disabled', false);
    el.select();
    document.execCommand("copy");
    el.prop('disabled', true);
    clearSelection();
    alert("Ссылка скопирована");
}

$(document).ready(function () {
    $('section#market').on('change', '.yandex-money-market-category-tree-switcher', marketAllCategoriesChangeHandler)
        .on('change', '.yandex-money-market-category-tree input[type="checkbox"]', marketCategoryClickHandler)
        .on('click', '.market-expand-all-category-box', marketShowCatAll)
        .on('click', '.market-collapse-all-category-box', marketHideCatAll)
        .on('click', '.market-check-all-category-box', marketCheckAll)
        .on('click', '.market-uncheck-all-category-box', marketUncheckAll)
        .on('click', '.yandex-money-market-edit-on-button', marketEditOnHandler);
    $('.yandex-money-market-currency-edit .edit_finish').on('click', marketCurrencyEditFinishHandler);
    $('.yandex-money-market-currency-edit .edit_finish_reset').on('click', marketCurrencyEditFinishResetHandler);
    $('.yandex-money-market-delivery-edit .edit_finish').on('click', marketDeliveryEditFinishHandler);
    $('.yandex-money-market-delivery-edit .edit_finish_reset').on('click', marketDeliveryEditFinishResetHandler);
    marketOfferFormatClickHandler();
    $('input[name="yandex_money_market_simple"]').on('change', marketOfferFormatClickHandler);
    $('.yandex-money-market-available').each(marketAvailableEditFinishHandler);
    $('.yandex-money-market-available-edit .edit_finish').on('click', marketAvailableEditFinishHandler);
    $('.yandex-money-market-available-edit .edit_finish_reset').on('click', marketAvailableEditFinishResetHandler);
    marketVatClickHandler();
    $('input[name="yandex_money_market_vat_enabled"]').on('change', marketVatClickHandler);
    $('.yandex-money-market-additional-condition-more').on('click', marketAddNewAdditionalCondition);
    $('.yandex-money-market-additional-condition-container')
        .on('click', '.edit_finish', marketAdditionalConditionEditFinishHandler)
        .on('click', '.edit_finish_reset', marketAdditionalConditionEditFinishResetHandler)
        .on('click', '.edit_finish_delete', marketAdditionalConditionDeleteHandler);
    $('.yandex-money-market-copy-url').on('click', marketCopyUrlToClipboard);

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
        var data = $form.serializeObject(!$form.hasClass('yandex_money_market'));
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
                if (jsonData.status === 'ok')
                {
                    var errors = jsonData.data.errors;
                    for (let type in errors){
                        let el = $('.'+type+'_errors');
                        el.html('');
                        for (let dd in errors[type]){
                            el.append(errors[type][dd]);
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

$.fn.serializeObject = function (checkboxesAsBools) {
    var o = {};
    var a = this.serializeArray({ checkboxesAsBools: checkboxesAsBools});
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