/**
 * GML Translation Editor — Manage translations per language
 *
 * @package GML_Translate
 * @since 2.7.0
 */
(function($) {
    'use strict';

    var state = {
        lang: '',
        langName: '',
        page: 1,
        search: '',
        filter: 'all',
        searchTimer: null
    };

    var i18n = gmlEditor.i18n;

    // Open editor modal
    $(document).on('click', '.gml-open-editor', function() {
        state.lang = $(this).data('lang');
        state.langName = $(this).data('lang-name');
        state.page = 1;
        state.search = '';
        state.filter = 'all';

        $('#gml-editor-title').text(i18n.manageTranslations + ' — ' + state.langName);
        $('#gml-editor-search').val('').attr('placeholder', i18n.search);
        $('#gml-editor-filter').val('all');
        $('#gml-editor-modal').fadeIn(200);
        loadTranslations();
    });

    // Close modal
    $('#gml-editor-close').on('click', function() {
        $('#gml-editor-modal').fadeOut(200);
    });
    $('#gml-editor-modal').on('click', function(e) {
        if (e.target === this) $(this).fadeOut(200);
    });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') $('#gml-editor-modal').fadeOut(200);
    });

    // Search with debounce
    $('#gml-editor-search').on('input', function() {
        clearTimeout(state.searchTimer);
        state.searchTimer = setTimeout(function() {
            state.search = $('#gml-editor-search').val();
            state.page = 1;
            loadTranslations();
        }, 400);
    });

    // Filter change
    $('#gml-editor-filter').on('change', function() {
        state.filter = $(this).val();
        state.page = 1;
        loadTranslations();
    });

    // Pagination
    $('#gml-editor-prev').on('click', function() {
        if (state.page > 1) { state.page--; loadTranslations(); }
    });
    $('#gml-editor-next').on('click', function() {
        if (!$(this).prop('disabled')) { state.page++; loadTranslations(); }
    });

    function loadTranslations() {
        var body = $('#gml-editor-body');
        body.html('<div style="padding:40px;text-align:center;color:#888;">' + i18n.loading + '</div>');

        $.post(gmlEditor.ajaxUrl, {
            action: 'gml_get_translations',
            nonce: gmlEditor.nonce,
            lang: state.lang,
            search: state.search,
            filter: state.filter,
            page: state.page
        }, function(r) {
            if (!r.success) return;
            var d = r.data;
            state.page = d.page;

            if (!d.rows || d.rows.length === 0) {
                body.html('<div style="padding:40px;text-align:center;color:#888;">' + i18n.noResults + '</div>');
                $('#gml-editor-info').text('');
                $('#gml-editor-prev, #gml-editor-next').prop('disabled', true);
                return;
            }

            var html = '<table style="width:100%;border-collapse:collapse;">';
            html += '<thead><tr style="background:#f9f9f9;">';
            html += '<th style="padding:10px 16px;text-align:left;border-bottom:1px solid #ddd;width:38%;">' + i18n.source + '</th>';
            html += '<th style="padding:10px 16px;text-align:left;border-bottom:1px solid #ddd;width:38%;">' + i18n.translation + '</th>';
            html += '<th style="padding:10px 16px;text-align:center;border-bottom:1px solid #ddd;width:8%;">' + i18n.status + '</th>';
            html += '<th style="padding:10px 16px;text-align:right;border-bottom:1px solid #ddd;width:16%;">' + i18n.actions + '</th>';
            html += '</tr></thead><tbody>';

            d.rows.forEach(function(row) {
                var statusBadge = row.status === 'manual'
                    ? '<span style="display:inline-block;padding:2px 8px;background:#e8f0fe;color:#1a73e8;border-radius:10px;font-size:11px;">M</span>'
                    : '<span style="display:inline-block;padding:2px 8px;background:#f0f0f0;color:#666;border-radius:10px;font-size:11px;">A</span>';

                var srcText = escHtml(row.source_text);
                var tgtText = escHtml(row.translated_text);
                if (srcText.length > 120) srcText = srcText.substring(0, 120) + '…';

                html += '<tr data-id="' + row.id + '" style="border-bottom:1px solid #f0f0f0;">';
                html += '<td style="padding:10px 16px;font-size:13px;color:#333;word-break:break-word;">' + srcText + '</td>';
                html += '<td class="gml-tgt-cell" style="padding:10px 16px;font-size:13px;word-break:break-word;">';
                html += '<span class="gml-tgt-text">' + tgtText + '</span>';
                html += '<textarea class="gml-tgt-input" style="display:none;width:100%;min-height:60px;padding:6px;border:1px solid #2271b1;border-radius:3px;font-size:13px;resize:vertical;">' + escHtml(row.translated_text) + '</textarea>';
                html += '</td>';
                html += '<td style="padding:10px 16px;text-align:center;">' + statusBadge + '</td>';
                html += '<td style="padding:10px 16px;text-align:right;white-space:nowrap;">';
                html += '<button type="button" class="button button-small gml-edit-btn">' + i18n.edit + '</button> ';
                html += '<button type="button" class="button button-small gml-save-btn" style="display:none;color:#00a32a;border-color:#00a32a;">' + i18n.save + '</button> ';
                html += '<button type="button" class="button button-small gml-cancel-btn" style="display:none;">' + i18n.cancel + '</button> ';
                html += '<button type="button" class="button button-small gml-del-btn" style="color:#d63638;border-color:#d63638;">' + i18n.delete + '</button>';
                html += '</td></tr>';
            });

            html += '</tbody></table>';
            body.html(html);

            // Page info
            var info = i18n.pageInfo.replace('%1$s', d.page).replace('%2$s', d.pages).replace('%3$s', d.total);
            $('#gml-editor-info').text(info);
            $('#gml-editor-prev').prop('disabled', d.page <= 1);
            $('#gml-editor-next').prop('disabled', d.page >= d.pages);
        });
    }

    // Edit button
    $(document).on('click', '.gml-edit-btn', function() {
        var tr = $(this).closest('tr');
        tr.find('.gml-tgt-text').hide();
        tr.find('.gml-tgt-input').show().focus();
        tr.find('.gml-edit-btn').hide();
        tr.find('.gml-save-btn, .gml-cancel-btn').show();
    });

    // Cancel edit — restore original text
    $(document).on('click', '.gml-cancel-btn', function() {
        var tr = $(this).closest('tr');
        var originalText = tr.find('.gml-tgt-text').text();
        tr.find('.gml-tgt-input').val(originalText).hide();
        tr.find('.gml-tgt-text').show();
        tr.find('.gml-save-btn, .gml-cancel-btn').hide();
        tr.find('.gml-edit-btn').show();
    });

    // Save edit
    $(document).on('click', '.gml-save-btn', function() {
        var btn = $(this);
        var tr = btn.closest('tr');
        var id = tr.data('id');
        var newText = tr.find('.gml-tgt-input').val();

        btn.prop('disabled', true).text('...');

        $.post(gmlEditor.ajaxUrl, {
            action: 'gml_save_translation',
            nonce: gmlEditor.nonce,
            id: id,
            translated_text: newText
        }, function(r) {
            if (r.success) {
                tr.find('.gml-tgt-text').text(newText).show();
                tr.find('.gml-tgt-input').hide();
                tr.find('.gml-save-btn, .gml-cancel-btn').hide();
                tr.find('.gml-edit-btn').show();
                // Update status badge to Manual
                tr.find('td:eq(2)').html('<span style="display:inline-block;padding:2px 8px;background:#e8f0fe;color:#1a73e8;border-radius:10px;font-size:11px;">M</span>');
            }
            btn.prop('disabled', false).text(i18n.save);
        });
    });

    // Delete translation
    $(document).on('click', '.gml-del-btn', function() {
        if (!confirm(i18n.confirmDelete)) return;
        var tr = $(this).closest('tr');
        var id = tr.data('id');

        $.post(gmlEditor.ajaxUrl, {
            action: 'gml_delete_translation',
            nonce: gmlEditor.nonce,
            id: id
        }, function(r) {
            if (r.success) {
                tr.fadeOut(300, function() { $(this).remove(); });
            }
        });
    });

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
