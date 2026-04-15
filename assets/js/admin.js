/* GML AI SEO — Admin JS */
(function($){
    'use strict';

    var box = document.getElementById('gml-seo-box-inner');
    if (!box) return;
    var pid = box.dataset.postId;

    // ── AI Generate ──────────────────────────────────────────────────
    $(document).on('click', '#gml-seo-gen-btn', function(){
        var btn = $(this);
        btn.prop('disabled', true).text('⏳ AI 深度分析中...');
        $('#gml-seo-loading').show();
        $('#gml-seo-report').html('');

        $.post(gmlSeo.ajax, {
            action: 'gml_seo_generate',
            post_id: pid,
            _wpnonce: gmlSeo.nonce
        }, function(r){
            $('#gml-seo-loading').hide();
            btn.prop('disabled', false).text('🤖 AI 重新分析');
            if (r.success) {
                // Server returns rendered HTML via the report, reload the page
                // to show the PHP-rendered report (simplest approach)
                location.reload();
            } else {
                $('#gml-seo-report').html('<p class="gml-seo-notice-warn">❌ ' + (r.data || 'Error') + '</p>');
            }
        }).fail(function(){
            $('#gml-seo-loading').hide();
            btn.prop('disabled', false).text('🤖 AI 重新分析');
            $('#gml-seo-report').html('<p class="gml-seo-notice-warn">❌ 请求失败，请重试</p>');
        });
    });

    // ── Save field edits ─────────────────────────────────────────────
    $(document).on('click', '#gml-seo-save-btn', function(){
        var btn = $(this);
        btn.prop('disabled', true);
        var fields = ['_gml_seo_title','_gml_seo_desc','_gml_seo_og_title','_gml_seo_og_desc','_gml_seo_keywords'];
        var pending = fields.length;

        fields.forEach(function(key){
            $.post(gmlSeo.ajax, {
                action: 'gml_seo_apply',
                post_id: pid,
                meta_key: key,
                meta_value: $('[name="'+key+'"]').val(),
                _wpnonce: gmlSeo.nonce
            }, function(){
                if (--pending <= 0) {
                    btn.prop('disabled', false);
                    $('#gml-seo-save-msg').show().delay(2000).fadeOut();
                }
            });
        });
    });

    // ── Live preview + counter ───────────────────────────────────────
    $(document).on('input', '.gml-seo-input', function(){
        $('#gml-seo-preview-title').text($('[name="_gml_seo_title"]').val() || '');
        $('#gml-seo-preview-desc').text($('[name="_gml_seo_desc"]').val() || '');

        $('.gml-seo-field').each(function(){
            var input = $(this).find('.gml-seo-input');
            var counter = $(this).find('.gml-seo-counter');
            if (!counter.length) return;
            var len = (input.val()||'').length;
            var max = parseInt(counter.data('max'));
            counter.find('.gml-seo-count').text(len);
            counter.removeClass('over ok');
            if (len > max) counter.addClass('over');
            else if (len > 0) counter.addClass('ok');
        });
    });

    // Init counters on load
    $('.gml-seo-field .gml-seo-counter').each(function(){
        var field = $(this).closest('.gml-seo-field');
        var len = (field.find('.gml-seo-input').val()||'').length;
        var max = parseInt($(this).data('max'));
        $(this).find('.gml-seo-count').text(len);
        if (len > max) $(this).addClass('over');
        else if (len > 0) $(this).addClass('ok');
    });

})(jQuery);
