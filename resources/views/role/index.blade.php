@extends('layouts.app')
@section('title', __('role.title'))

@section('content')
<div class="row g-4">
    {{-- Role Tabs --}}
    <div class="col-12">
        <div class="glass p-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <h6 class="fw-700 mb-0" style="color:var(--text-primary)">
                    <i class="bi bi-shield-lock me-2"></i>Pilih Role
                </h6>
                <button class="btn btn-glass btn-sm" id="btnAddRole">
                    <i class="bi bi-plus-lg me-1"></i>{{ __('role.add') }}
                </button>
            </div>
            <div class="role-tabs d-flex flex-wrap gap-2" id="roleTabs">
                {{-- Populated by JS --}}
            </div>
        </div>
    </div>

    {{-- Permission Grid --}}
    <div class="col-12">
        <div class="glass p-4" id="permissionPanel">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
                <div>
                    <h6 class="fw-700 mb-1" style="color:var(--text-primary)">
                        <i class="bi bi-key-fill me-2 text-accent"></i>Hak Akses: <span id="activeRoleLabel" class="text-accent">—</span>
                    </h6>
                    <small class="text-muted" id="permHint">Pilih role di atas untuk mengelola hak akses.</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge badge-glass d-none" id="unsavedBadge">
                        <i class="bi bi-exclamation-circle me-1"></i>Belum disimpan
                    </span>
                    <button class="btn btn-accent btn-sm d-none" id="btnSave" disabled>
                        <span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span>
                        <i class="bi bi-check2-circle me-1" id="saveIcon"></i>{{ __('role.save_changes') }}
                    </button>
                </div>
            </div>

            <div id="noRoleSelected" class="text-center py-5">
                <i class="bi bi-shield-lock" style="font-size:3rem;opacity:.2;color:var(--text-muted)"></i>
                <p class="mt-2" style="color:var(--text-muted)">Pilih role untuk melihat dan mengatur hak akses</p>
            </div>

            <div id="permissionGrid" class="d-none">
                <div class="row g-3" id="groupContainer">
                    {{-- Populated by JS --}}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Add Role Modal --}}
<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700">
                    <i class="bi bi-plus-circle me-2 text-accent"></i>{{ __('role.add_new') }}
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addRoleForm">
                <div class="modal-body">
                    <label class="form-label">{{ __('role.label_name') }} <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="50"
                           placeholder="contoh: warehouse_manager" pattern="^[a-z_]+$">
                    <small class="text-muted">Huruf kecil dan underscore saja</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-accent btn-sm">Buat Role</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    .role-pill {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .45rem 1rem;
        border-radius: 999px;
        font-size: .82rem;
        font-weight: 600;
        cursor: pointer;
        transition: all .2s ease;
        background: var(--btn-glass-bg);
        border: 1px solid var(--btn-glass-border);
        color: var(--btn-glass-color);
        user-select: none;
    }
    .role-pill:hover {
        background: var(--btn-glass-hover-bg);
        color: var(--btn-glass-hover-color);
        border-color: rgba(var(--accent-rgb), .25);
    }
    .role-pill.active {
        background: rgba(var(--accent-rgb), .15);
        border-color: rgba(var(--accent-rgb), .4);
        color: var(--accent);
        box-shadow: 0 0 12px rgba(var(--accent-rgb), .15);
    }

    .perm-group {
        background: var(--glass-bg);
        backdrop-filter: blur(var(--glass-blur));
        border: 1px solid var(--glass-border);
        border-radius: var(--radius-xl);
        padding: 1.25rem;
        transition: border-color .2s ease, box-shadow .2s ease;
    }
    .perm-group:hover {
        border-color: rgba(var(--accent-rgb), .15);
    }
    .perm-group-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: .85rem;
        padding-bottom: .6rem;
        border-bottom: 1px solid var(--glass-border);
    }
    .perm-group-title {
        display: flex;
        align-items: center;
        gap: .5rem;
        font-size: .85rem;
        font-weight: 700;
        color: var(--text-primary);
    }
    .perm-group-title i {
        font-size: 1rem;
        color: var(--accent);
    }
    .perm-group-toggle {
        font-size: .72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .05em;
        cursor: pointer;
        color: var(--accent);
        background: none;
        border: none;
        padding: .2rem .5rem;
        border-radius: var(--radius-xl);
        transition: background .15s ease;
    }
    .perm-group-toggle:hover {
        background: rgba(var(--accent-rgb), .1);
    }

    .perm-check {
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .35rem .6rem;
        border-radius: .75rem;
        transition: background .15s ease;
        cursor: pointer;
    }
    .perm-check:hover {
        background: rgba(var(--accent-rgb), .06);
    }
    .perm-check input[type="checkbox"] {
        width: 1.1rem;
        height: 1.1rem;
        border-radius: .35rem;
        border: 2px solid var(--input-border);
        background: var(--input-bg);
        cursor: pointer;
        accent-color: var(--accent);
        flex-shrink: 0;
    }
    .perm-check input[type="checkbox"]:checked {
        border-color: var(--accent);
    }
    .perm-check label {
        font-size: .82rem;
        color: var(--text-secondary);
        cursor: pointer;
        margin-bottom: 0;
        text-transform: capitalize;
    }
    .perm-check input:disabled + label {
        opacity: .5;
    }

    .perm-counter {
        font-size: .7rem;
        font-weight: 600;
        color: var(--text-muted);
        background: var(--badge-bg);
        border: 1px solid var(--badge-border);
        border-radius: 999px;
        padding: .1rem .5rem;
    }

    @media (max-width: 768px) {
        .perm-group {
            padding: 1rem;
        }
    }
</style>
@endpush

@push('scripts')
<script>
(function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const rolesData = @json($rolesData);
    const groups    = @json($groups);

    let activeRoleId   = null;
    let originalPerms  = [];
    let currentPerms   = [];
    let isSuperAdmin   = false;

    const $tabs          = document.getElementById('roleTabs');
    const $grid          = document.getElementById('permissionGrid');
    const $groupCont     = document.getElementById('groupContainer');
    const $noRole        = document.getElementById('noRoleSelected');
    const $roleLabel     = document.getElementById('activeRoleLabel');
    const $hint          = document.getElementById('permHint');
    const $btnSave       = document.getElementById('btnSave');
    const $saveSpinner   = document.getElementById('saveSpinner');
    const $saveIcon      = document.getElementById('saveIcon');
    const $unsavedBadge  = document.getElementById('unsavedBadge');

    function renderRoleTabs() {
        $tabs.innerHTML = '';
        rolesData.forEach(function(role) {
            const pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'role-pill' + (role.id === activeRoleId ? ' active' : '');
            pill.dataset.roleId = role.id;
            pill.innerHTML = '<i class="bi bi-shield-fill"></i> ' + escapeHtml(role.label);
            pill.addEventListener('click', function() { selectRole(role.id); });
            $tabs.appendChild(pill);
        });
    }

    function renderPermissionGrid() {
        $groupCont.innerHTML = '';
        groups.forEach(function(group) {
            const col = document.createElement('div');
            col.className = 'col-md-6 col-xl-4';

            let checksHtml = '';
            group.items.forEach(function(perm) {
                const checked  = currentPerms.indexOf(perm.id) !== -1 ? 'checked' : '';
                const disabled = isSuperAdmin ? 'disabled' : '';
                checksHtml += '<div class="perm-check">' +
                    '<input type="checkbox" class="perm-cb" id="perm_' + perm.id + '" value="' + perm.id + '" ' + checked + ' ' + disabled + '>' +
                    '<label for="perm_' + perm.id + '">' + escapeHtml(perm.label || perm.name) + '</label>' +
                    '</div>';
            });

            const checkedCount = group.items.filter(function(p) { return currentPerms.indexOf(p.id) !== -1; }).length;
            const allChecked = checkedCount === group.items.length;

            col.innerHTML =
                '<div class="perm-group h-100">' +
                    '<div class="perm-group-header">' +
                        '<div class="perm-group-title">' +
                            '<i class="bi ' + group.icon + '"></i>' +
                            '<span>' + escapeHtml(group.name) + '</span>' +
                            '<span class="perm-counter">' + checkedCount + '/' + group.items.length + '</span>' +
                        '</div>' +
                        (isSuperAdmin ? '' : '<button type="button" class="perm-group-toggle" data-group="' + escapeHtml(group.name) + '">' + (allChecked ? 'Hapus Semua' : 'Pilih Semua') + '</button>') +
                    '</div>' +
                    '<div class="perm-items">' + checksHtml + '</div>' +
                '</div>';

            $groupCont.appendChild(col);
        });

        bindCheckboxEvents();
    }

    function bindCheckboxEvents() {
        document.querySelectorAll('.perm-cb').forEach(function(cb) {
            cb.addEventListener('change', function() {
                const id = parseInt(this.value);
                if (this.checked) {
                    if (currentPerms.indexOf(id) === -1) currentPerms.push(id);
                } else {
                    currentPerms = currentPerms.filter(function(p) { return p !== id; });
                }
                updateGroupCounters();
                checkUnsaved();
            });
        });

        document.querySelectorAll('.perm-group-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const groupName = this.dataset.group;
                const group = groups.find(function(g) { return g.name === groupName; });
                if (!group) return;

                const groupIds = group.items.map(function(p) { return p.id; });
                const allChecked = groupIds.every(function(id) { return currentPerms.indexOf(id) !== -1; });

                if (allChecked) {
                    currentPerms = currentPerms.filter(function(id) { return groupIds.indexOf(id) === -1; });
                } else {
                    groupIds.forEach(function(id) {
                        if (currentPerms.indexOf(id) === -1) currentPerms.push(id);
                    });
                }

                renderPermissionGrid();
                checkUnsaved();
            });
        });
    }

    function updateGroupCounters() {
        document.querySelectorAll('.perm-group').forEach(function(groupEl) {
            const cbs = groupEl.querySelectorAll('.perm-cb');
            const total = cbs.length;
            const checked = Array.from(cbs).filter(function(c) { return c.checked; }).length;
            const counter = groupEl.querySelector('.perm-counter');
            if (counter) counter.textContent = checked + '/' + total;

            const toggle = groupEl.querySelector('.perm-group-toggle');
            if (toggle) {
                toggle.textContent = (checked === total) ? 'Hapus Semua' : 'Pilih Semua';
            }
        });
    }

    function selectRole(roleId) {
        activeRoleId = roleId;
        const role = rolesData.find(function(r) { return r.id === roleId; });
        if (!role) return;

        isSuperAdmin = role.name === 'super_admin';
        originalPerms = role.permissions.slice();
        currentPerms  = role.permissions.slice();

        $roleLabel.textContent = role.label;
        $hint.textContent = isSuperAdmin
            ? 'Super Admin memiliki semua hak akses secara otomatis.'
            : 'Centang hak akses yang diinginkan, lalu klik Simpan.';

        $noRole.classList.add('d-none');
        $grid.classList.remove('d-none');
        $btnSave.classList.remove('d-none');

        renderRoleTabs();
        renderPermissionGrid();
        checkUnsaved();
    }

    function checkUnsaved() {
        if (isSuperAdmin) {
            $btnSave.disabled = true;
            $unsavedBadge.classList.add('d-none');
            return;
        }
        const a = originalPerms.slice().sort();
        const b = currentPerms.slice().sort();
        const changed = JSON.stringify(a) !== JSON.stringify(b);
        $btnSave.disabled = !changed;
        $unsavedBadge.classList.toggle('d-none', !changed);
    }

    $btnSave.addEventListener('click', function() {
        if (!activeRoleId || isSuperAdmin) return;

        $saveSpinner.classList.remove('d-none');
        $saveIcon.classList.add('d-none');
        $btnSave.disabled = true;

        fetch('/roles/' + activeRoleId + '/permissions', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ permissions: currentPerms }),
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            $saveSpinner.classList.add('d-none');
            $saveIcon.classList.remove('d-none');

            if (data.success) {
                originalPerms = currentPerms.slice();
                var role = rolesData.find(function(r) { return r.id === activeRoleId; });
                if (role) role.permissions = currentPerms.slice();
                checkUnsaved();
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: data.message,
                    showConfirmButton: false,
                    timer: 2500,
                    timerProgressBar: true,
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Gagal', text: data.message || 'Terjadi kesalahan.' });
                $btnSave.disabled = false;
            }
        })
        .catch(function() {
            $saveSpinner.classList.add('d-none');
            $saveIcon.classList.remove('d-none');
            $btnSave.disabled = false;
            Swal.fire({ icon: 'error', title: 'Error', text: 'Gagal menyimpan. Coba lagi.' });
        });
    });

    document.getElementById('addRoleForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const name = this.querySelector('[name="name"]').value.trim();
        if (!name) return;

        fetch('/roles', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ name: name }),
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                rolesData.push(data.role);
                renderRoleTabs();
                bootstrap.Modal.getInstance(document.getElementById('addRoleModal')).hide();
                e.target.reset();
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: data.message,
                    showConfirmButton: false,
                    timer: 2500,
                    timerProgressBar: true,
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Gagal', text: data.message || 'Terjadi kesalahan.' });
            }
        })
        .catch(function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Gagal membuat role.' });
        });
    });

    document.getElementById('btnAddRole').addEventListener('click', function() {
        new bootstrap.Modal(document.getElementById('addRoleModal')).show();
    });

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    renderRoleTabs();
})();
</script>
@endpush
