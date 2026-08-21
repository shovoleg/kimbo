document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var selectAll   = document.getElementById('selectAll');
    var rowChecks   = Array.prototype.slice.call(document.querySelectorAll('.js-row-check'));
    var needsSel    = Array.prototype.slice.call(document.querySelectorAll('.js-needs-selection'));
    var counter     = document.getElementById('selectionCounter');
    var filterInput = document.getElementById('filterInput');
    var form        = document.getElementById('toolbarForm');
    var actionField = document.getElementById('actionField');

    var tooltipNodes = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    Array.prototype.forEach.call(tooltipNodes, function (node) {
        new bootstrap.Tooltip(node);
    });

    function checkedRows() {
        return rowChecks.filter(function (cb) {
            return cb.checked;
        });
    }

    function refresh() {
        var n = checkedRows().length;

        needsSel.forEach(function (btn) {
            btn.disabled = (n === 0);
        });

        counter.textContent = n > 0 ? (n + ' selected') : '';

        if (selectAll) {

            var visible = rowChecks.filter(isRowVisible);
            var visibleChecked = visible.filter(function (cb) { return cb.checked; });

            selectAll.checked = visible.length > 0 && visibleChecked.length === visible.length;
            selectAll.indeterminate = visibleChecked.length > 0 && visibleChecked.length < visible.length;
        }
    }

    function isRowVisible(cb) {
        var tr = cb.closest('tr');
        return tr && tr.style.display !== 'none';
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {

            rowChecks.filter(isRowVisible).forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            refresh();
        });
    }

    rowChecks.forEach(function (cb) {
        cb.addEventListener('change', refresh);
    });

    if (filterInput) {
        filterInput.addEventListener('input', function () {
            var q = filterInput.value.trim().toLowerCase();

            Array.prototype.forEach.call(
                document.querySelectorAll('#usersTable tbody tr'),
                function (tr) {
                    if (!tr.querySelector('.js-row-check')) {
                        return;
                    }
                    var text = tr.textContent.toLowerCase();
                    var show = (q === '' || text.indexOf(q) !== -1);
                    tr.style.display = show ? '' : 'none';

                    if (!show) {
                        var cb = tr.querySelector('.js-row-check');
                        if (cb) { cb.checked = false; }
                    }
                }
            );
            refresh();
        });
    }

    var pendingAction = null;
    var modalEl = document.getElementById('confirmModal');
    var modal   = modalEl ? new bootstrap.Modal(modalEl) : null;

    form.addEventListener('submit', function (e) {
        var btn = e.submitter;
        if (!btn) {
            return;
        }

        var action = btn.getAttribute('data-action');
        actionField.value = action;

        var dangerous = (action === 'delete' || action === 'delete_unverified');

        if (dangerous && modal && pendingAction === null) {
            e.preventDefault();
            pendingAction = action;

            var n = checkedRows().length;
            document.getElementById('confirmText').textContent =
                (action === 'delete')
                    ? 'Delete ' + n + (n === 1 ? ' selected user' : ' selected users') + '? This cannot be undone.'
                    : 'Delete all unverified users? This cannot be undone.';

            modal.show();
        }
    });

    var confirmBtn = document.getElementById('confirmOk');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            actionField.value = pendingAction;
            modal.hide();

            var act = pendingAction;
            pendingAction = 'confirmed';

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(form.querySelector('[data-action="' + act + '"]'));
            } else {
                form.submit();
            }
        });
    }

    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (pendingAction !== 'confirmed') {
                pendingAction = null;
            }
        });
    }

    refresh();
});
