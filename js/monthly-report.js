/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>; GPL-3.0-or-later */
'use strict';
document.addEventListener('DOMContentLoaded', function () {
  const account = document.getElementById('kreabank-account-context');
  const month = document.getElementById('kb-monthly-month');
  const balance = document.getElementById('kb-monthly-balance');
  const balanceAccount = document.getElementById('kb-monthly-balance-account');
  const balanceMonth = document.getElementById('kb-monthly-balance-month');
  if (account && month && balance && balanceAccount && balanceMonth) {
    const bindBalance = function () { balanceAccount.value = account.value; balanceMonth.value = month.value; };
    const changeScope = function () { balance.value = ''; bindBalance(); };
    account.addEventListener('change', changeScope);
    month.addEventListener('change', changeScope);
    balance.addEventListener('input', bindBalance);
  }
  const printButton = document.getElementById('kb-monthly-print');
  if (printButton) {
    document.body.classList.add('kb-monthly-print');
    printButton.addEventListener('click', function () { window.print(); });
  }
});
