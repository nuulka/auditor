<?php

class ReconciliationStatus {
    const UNCHECKED = 'UNCHECKED';
    const MATCHED = 'MATCHED';                     // OK
    const TIMING_DIFFERENCE = 'TIMING_DIFFERENCE'; // CSUSZAS
    const MISSING_DOCUMENT = 'MISSING_DOCUMENT';   // HIÁNY
    const AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';     // ELTÉRÉS
    const MANY_TO_ONE = 'MANY_TO_ONE';             // ÖSSZEVONT (Több OTS -> 1 Bank)
    const ONE_TO_MANY = 'ONE_TO_MANY';             // ÖSSZEVONT2 (1 OTS -> Több Bank)
}
