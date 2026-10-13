import axios from 'axios';
import jQuery from 'jquery';
import * as Popper from '@popperjs/core';
import * as bootstrap from 'bootstrap';
import select2 from 'select2';
import Inputmask from 'inputmask';
import Swal from 'sweetalert2';
import DataTable from 'datatables.net-bs5';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.$ = window.jQuery = jQuery;
window.Popper = Popper;
window.bootstrap = bootstrap;
window.Inputmask = Inputmask;
window.Swal = Swal;
window.DataTable = DataTable;

select2(jQuery);

const tokenEl = document.querySelector('meta[name="csrf-token"]');
window.token = tokenEl ? tokenEl.content : '';
if (tokenEl) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = tokenEl.content;
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': tokenEl.content } });
}
