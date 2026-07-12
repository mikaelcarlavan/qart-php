//! Élimination gaussienne GF(2) — miroir exact de `Solver::eliminate` (PHP).
//!
//! Les colonnes et compositions sont des bitsets packés MSB d'abord,
//! concaténés dans deux buffers contigus. La sélection des pivots suit
//! strictement l'ordre d'importance fourni, et pour chaque position le
//! premier vecteur libre portant le bit : le résultat est identique octet
//! pour octet à l'implémentation PHP pure.

use std::slice;

/// # Safety
/// Les pointeurs doivent référencer des buffers valides des tailles
/// annoncées : `cols` (ncols * col_bytes), `comp` (ncols * comp_bytes),
/// `order` (order_len), `pivot_pos`/`pivot_col` (capacité ncols).
#[no_mangle]
pub unsafe extern "C" fn qart_eliminate(
    cols: *mut u8,
    ncols: usize,
    col_bytes: usize,
    comp: *mut u8,
    comp_bytes: usize,
    order: *const u32,
    order_len: usize,
    pivot_pos: *mut u32,
    pivot_col: *mut u32,
) -> usize {
    if ncols == 0 || col_bytes == 0 {
        return 0;
    }
    let cols = slice::from_raw_parts_mut(cols, ncols * col_bytes);
    let comp = slice::from_raw_parts_mut(comp, ncols * comp_bytes);
    let order = slice::from_raw_parts(order, order_len);
    let pivot_pos = slice::from_raw_parts_mut(pivot_pos, ncols);
    let pivot_col = slice::from_raw_parts_mut(pivot_col, ncols);

    let mut unused = vec![true; ncols];
    let mut remaining = ncols;
    let mut npiv = 0usize;
    // copies du pivot : évite l'aliasing dans le buffer contigu
    let mut piv_col_copy = vec![0u8; col_bytes];
    let mut piv_comp_copy = vec![0u8; comp_bytes];

    for &pos in order {
        let byte = (pos >> 3) as usize;
        let mask = 0x80u8 >> (pos & 7);

        let mut piv = usize::MAX;
        for (j, free) in unused.iter().enumerate() {
            if *free && cols[j * col_bytes + byte] & mask != 0 {
                piv = j;
                break;
            }
        }
        if piv == usize::MAX {
            continue;
        }
        unused[piv] = false;
        piv_col_copy.copy_from_slice(&cols[piv * col_bytes..(piv + 1) * col_bytes]);
        piv_comp_copy.copy_from_slice(&comp[piv * comp_bytes..(piv + 1) * comp_bytes]);

        for j in (piv + 1)..ncols {
            if unused[j] && cols[j * col_bytes + byte] & mask != 0 {
                xor_into(
                    &mut cols[j * col_bytes..(j + 1) * col_bytes],
                    &piv_col_copy,
                );
                xor_into(
                    &mut comp[j * comp_bytes..(j + 1) * comp_bytes],
                    &piv_comp_copy,
                );
            }
        }

        pivot_pos[npiv] = pos;
        pivot_col[npiv] = piv as u32;
        npiv += 1;
        remaining -= 1;
        if remaining == 0 {
            break;
        }
    }

    npiv
}

#[inline]
fn xor_into(dst: &mut [u8], src: &[u8]) {
    for (d, s) in dst.iter_mut().zip(src) {
        *d ^= *s;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn elimination_triviale() {
        // 2 colonnes de 1 octet : e1 = 0b1000_0000, e2 = 0b0100_0000
        let mut cols = vec![0b1000_0000u8, 0b0100_0000u8];
        let mut comp = vec![0b1000_0000u8, 0b0100_0000u8];
        let order = [0u32, 1u32];
        let mut pos = [0u32; 2];
        let mut col = [0u32; 2];
        let n = unsafe {
            qart_eliminate(
                cols.as_mut_ptr(), 2, 1,
                comp.as_mut_ptr(), 1,
                order.as_ptr(), 2,
                pos.as_mut_ptr(), col.as_mut_ptr(),
            )
        };
        assert_eq!(n, 2);
        assert_eq!((pos[0], col[0]), (0, 0));
        assert_eq!((pos[1], col[1]), (1, 1));
    }
}
