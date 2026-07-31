<?php
// Minimal, self-contained chess engine for authoritative server-side move
// validation. No dependencies. Square index = (rank-1)*8 + file, so a1=0,
// h1=7, a8=56, h8=63. Board is a 64-element array of piece chars ('' = empty),
// white = uppercase PNBRQK, black = lowercase.
//
// Public API:
//   cz_start_fen()                       -> standard initial FEN
//   cz_fen_parse($fen)                   -> state array | null
//   cz_fen_export($state)                -> FEN string
//   cz_apply_move($state, $from,$to,$promo) -> [newState, meta] | null (illegal)
//        $from/$to are algebraic ("e2"). meta = [check,checkmate,stalemate].
//   cz_board_key($fenOrState)            -> "placement w" for tamper comparison

function cz_start_fen()
{
    return "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1";
}

function cz_sq($file, $rank)
{ // file 0-7, rank 1-8
    return ($rank - 1) * 8 + $file;
}
function cz_file($sq)
{
    return $sq % 8;
}
function cz_rank($sq)
{
    return intdiv($sq, 8) + 1;
}
function cz_alg_to_sq($a)
{
    if (!is_string($a) || strlen($a) < 2)
        return -1;
    $f = ord(strtolower($a[0])) - ord('a');
    $r = intval($a[1]);
    if ($f < 0 || $f > 7 || $r < 1 || $r > 8)
        return -1;
    return cz_sq($f, $r);
}
function cz_sq_to_alg($sq)
{
    return chr(ord('a') + cz_file($sq)) . cz_rank($sq);
}
function cz_is_white($p)
{
    return $p !== '' && ctype_upper($p);
}
function cz_is_black($p)
{
    return $p !== '' && ctype_lower($p);
}
function cz_color_of($p)
{
    return $p === '' ? '' : (ctype_upper($p) ? 'w' : 'b');
}

function cz_fen_parse($fen)
{
    if (!is_string($fen) || $fen === '')
        return null;
    if ($fen === 'start')
        $fen = cz_start_fen();
    $parts = preg_split('/\s+/', trim($fen));
    if (count($parts) < 2)
        return null;

    $board = array_fill(0, 64, '');
    $rows = explode('/', $parts[0]);
    if (count($rows) !== 8)
        return null;
    // FEN lists rank 8 first.
    for ($i = 0; $i < 8; $i++) {
        $rank = 8 - $i;
        $file = 0;
        $row = $rows[$i];
        for ($c = 0; $c < strlen($row); $c++) {
            $ch = $row[$c];
            if (ctype_digit($ch)) {
                $file += intval($ch);
            } else {
                if ($file > 7)
                    return null;
                if (strpos('PNBRQKpnbrqk', $ch) === false)
                    return null;
                $board[cz_sq($file, $rank)] = $ch;
                $file++;
            }
        }
        if ($file !== 8)
            return null;
    }

    $side = ($parts[1] === 'b') ? 'b' : 'w';
    $castle = $parts[2] ?? '-';
    $ep = $parts[3] ?? '-';
    $half = isset($parts[4]) ? intval($parts[4]) : 0;
    $full = isset($parts[5]) ? intval($parts[5]) : 1;

    return [
        'board' => $board,
        'side' => $side,
        'castle' => ($castle === '' ? '-' : $castle),
        'ep' => $ep,
        'half' => $half,
        'full' => $full,
    ];
}

function cz_fen_export($s)
{
    $out = '';
    for ($rank = 8; $rank >= 1; $rank--) {
        $empty = 0;
        for ($file = 0; $file < 8; $file++) {
            $p = $s['board'][cz_sq($file, $rank)];
            if ($p === '') {
                $empty++;
            } else {
                if ($empty > 0) {
                    $out .= $empty;
                    $empty = 0;
                }
                $out .= $p;
            }
        }
        if ($empty > 0)
            $out .= $empty;
        if ($rank > 1)
            $out .= '/';
    }
    $castle = ($s['castle'] === '' ? '-' : $s['castle']);
    return $out . ' ' . $s['side'] . ' ' . $castle . ' ' . $s['ep'] . ' ' . $s['half'] . ' ' . $s['full'];
}

// Board+side signature only (ignores clocks/ep/castle) for tamper comparison.
function cz_board_key($x)
{
    $s = is_array($x) ? $x : cz_fen_parse($x);
    if (!$s)
        return '';
    $fen = cz_fen_export($s);
    $p = explode(' ', $fen);
    return $p[0] . ' ' . $p[1];
}

// Is $sq attacked by side $by ('w'|'b') on this board?
function cz_attacked($board, $sq, $by)
{
    if ($sq < 0 || $sq > 63)
        return false;
    $f = cz_file($sq);
    $r = cz_rank($sq);

    // Pawn attacks.
    if ($by === 'w') {
        foreach ([[-1, -1], [1, -1]] as $d) { // white pawn sits one rank below
            $af = $f + $d[0];
            $ar = $r + $d[1];
            if ($af >= 0 && $af <= 7 && $ar >= 1 && $ar <= 8 && $board[cz_sq($af, $ar)] === 'P')
                return true;
        }
    } else {
        foreach ([[-1, 1], [1, 1]] as $d) {
            $af = $f + $d[0];
            $ar = $r + $d[1];
            if ($af >= 0 && $af <= 7 && $ar >= 1 && $ar <= 8 && $board[cz_sq($af, $ar)] === 'p')
                return true;
        }
    }

    // Knight.
    $kn = $by === 'w' ? 'N' : 'n';
    foreach ([[1, 2], [2, 1], [2, -1], [1, -2], [-1, -2], [-2, -1], [-2, 1], [-1, 2]] as $d) {
        $af = $f + $d[0];
        $ar = $r + $d[1];
        if ($af >= 0 && $af <= 7 && $ar >= 1 && $ar <= 8 && $board[cz_sq($af, $ar)] === $kn)
            return true;
    }

    // King.
    $kg = $by === 'w' ? 'K' : 'k';
    for ($df = -1; $df <= 1; $df++)
        for ($dr = -1; $dr <= 1; $dr++) {
            if ($df === 0 && $dr === 0)
                continue;
            $af = $f + $df;
            $ar = $r + $dr;
            if ($af >= 0 && $af <= 7 && $ar >= 1 && $ar <= 8 && $board[cz_sq($af, $ar)] === $kg)
                return true;
        }

    // Sliding: rook/queen orthogonal, bishop/queen diagonal.
    $rq = $by === 'w' ? ['R', 'Q'] : ['r', 'q'];
    $bq = $by === 'w' ? ['B', 'Q'] : ['b', 'q'];
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as $d) {
        $af = $f;
        $ar = $r;
        while (true) {
            $af += $d[0];
            $ar += $d[1];
            if ($af < 0 || $af > 7 || $ar < 1 || $ar > 8)
                break;
            $p = $board[cz_sq($af, $ar)];
            if ($p === '')
                continue;
            if (in_array($p, $rq, true))
                return true;
            break;
        }
    }
    foreach ([[1, 1], [1, -1], [-1, 1], [-1, -1]] as $d) {
        $af = $f;
        $ar = $r;
        while (true) {
            $af += $d[0];
            $ar += $d[1];
            if ($af < 0 || $af > 7 || $ar < 1 || $ar > 8)
                break;
            $p = $board[cz_sq($af, $ar)];
            if ($p === '')
                continue;
            if (in_array($p, $bq, true))
                return true;
            break;
        }
    }
    return false;
}

function cz_king_sq($board, $color)
{
    $k = $color === 'w' ? 'K' : 'k';
    for ($i = 0; $i < 64; $i++)
        if ($board[$i] === $k)
            return $i;
    return -1;
}

function cz_in_check($state, $color)
{
    $ks = cz_king_sq($state['board'], $color);
    if ($ks < 0)
        return false;
    return cz_attacked($state['board'], $ks, $color === 'w' ? 'b' : 'w');
}

// Generate pseudo-legal moves for the side to move (king-safety not yet checked).
// Each move: ['from'=>i,'to'=>j,'promo'=>char|'', 'flag'=>'', castling/ep noted].
function cz_pseudo_moves($s)
{
    $board = $s['board'];
    $side = $s['side'];
    $moves = [];
    $own = $side === 'w' ? 'cz_is_white' : 'cz_is_black';
    $opp = $side === 'w' ? 'cz_is_black' : 'cz_is_white';
    $epSq = ($s['ep'] !== '-' ? cz_alg_to_sq($s['ep']) : -1);

    for ($i = 0; $i < 64; $i++) {
        $p = $board[$i];
        if ($p === '' || cz_color_of($p) !== $side)
            continue;
        $f = cz_file($i);
        $r = cz_rank($i);
        $type = strtoupper($p);

        if ($type === 'P') {
            $dir = $side === 'w' ? 1 : -1;
            $startRank = $side === 'w' ? 2 : 7;
            $promoRank = $side === 'w' ? 8 : 1;
            // forward one
            $r1 = $r + $dir;
            if ($r1 >= 1 && $r1 <= 8 && $board[cz_sq($f, $r1)] === '') {
                cz_add_pawn($moves, $i, cz_sq($f, $r1), $r1 === $promoRank);
                // forward two
                $r2 = $r + 2 * $dir;
                if ($r === $startRank && $board[cz_sq($f, $r2)] === '') {
                    $moves[] = ['from' => $i, 'to' => cz_sq($f, $r2), 'promo' => '', 'dbl' => true];
                }
            }
            // captures
            foreach ([-1, 1] as $df) {
                $cf = $f + $df;
                if ($cf < 0 || $cf > 7 || $r1 < 1 || $r1 > 8)
                    continue;
                $tsq = cz_sq($cf, $r1);
                $tp = $board[$tsq];
                if ($tp !== '' && $opp($tp)) {
                    cz_add_pawn($moves, $i, $tsq, $r1 === $promoRank);
                } elseif ($tsq === $epSq && $epSq >= 0) {
                    $moves[] = ['from' => $i, 'to' => $tsq, 'promo' => '', 'ep' => true];
                }
            }
        } elseif ($type === 'N') {
            foreach ([[1, 2], [2, 1], [2, -1], [1, -2], [-1, -2], [-2, -1], [-2, 1], [-1, 2]] as $d) {
                $af = $f + $d[0];
                $ar = $r + $d[1];
                if ($af < 0 || $af > 7 || $ar < 1 || $ar > 8)
                    continue;
                $tsq = cz_sq($af, $ar);
                if ($board[$tsq] === '' || $opp($board[$tsq]))
                    $moves[] = ['from' => $i, 'to' => $tsq, 'promo' => ''];
            }
        } elseif ($type === 'K') {
            for ($df = -1; $df <= 1; $df++)
                for ($dr = -1; $dr <= 1; $dr++) {
                    if ($df === 0 && $dr === 0)
                        continue;
                    $af = $f + $df;
                    $ar = $r + $dr;
                    if ($af < 0 || $af > 7 || $ar < 1 || $ar > 8)
                        continue;
                    $tsq = cz_sq($af, $ar);
                    if ($board[$tsq] === '' || $opp($board[$tsq]))
                        $moves[] = ['from' => $i, 'to' => $tsq, 'promo' => ''];
                }
            // castling
            cz_add_castles($moves, $s, $i);
        } else {
            // sliders B/R/Q
            $dirs = [];
            if ($type === 'B' || $type === 'Q')
                $dirs = array_merge($dirs, [[1, 1], [1, -1], [-1, 1], [-1, -1]]);
            if ($type === 'R' || $type === 'Q')
                $dirs = array_merge($dirs, [[1, 0], [-1, 0], [0, 1], [0, -1]]);
            foreach ($dirs as $d) {
                $af = $f;
                $ar = $r;
                while (true) {
                    $af += $d[0];
                    $ar += $d[1];
                    if ($af < 0 || $af > 7 || $ar < 1 || $ar > 8)
                        break;
                    $tsq = cz_sq($af, $ar);
                    if ($board[$tsq] === '') {
                        $moves[] = ['from' => $i, 'to' => $tsq, 'promo' => ''];
                    } else {
                        if ($opp($board[$tsq]))
                            $moves[] = ['from' => $i, 'to' => $tsq, 'promo' => ''];
                        break;
                    }
                }
            }
        }
    }
    return $moves;
}

function cz_add_pawn(&$moves, $from, $to, $isPromo)
{
    if ($isPromo) {
        foreach (['q', 'r', 'b', 'n'] as $pr)
            $moves[] = ['from' => $from, 'to' => $to, 'promo' => $pr];
    } else {
        $moves[] = ['from' => $from, 'to' => $to, 'promo' => ''];
    }
}

function cz_add_castles(&$moves, $s, $kingSq)
{
    $board = $s['board'];
    $side = $s['side'];
    $castle = $s['castle'];
    $opp = $side === 'w' ? 'b' : 'w';
    if ($side === 'w' && $kingSq === cz_sq(4, 1)) {
        // King side: squares f1,g1 empty; e1,f1,g1 not attacked; right 'K'.
        if (strpos($castle, 'K') !== false && $board[cz_sq(5, 1)] === '' && $board[cz_sq(6, 1)] === ''
            && !cz_attacked($board, cz_sq(4, 1), $opp) && !cz_attacked($board, cz_sq(5, 1), $opp) && !cz_attacked($board, cz_sq(6, 1), $opp)) {
            $moves[] = ['from' => $kingSq, 'to' => cz_sq(6, 1), 'promo' => '', 'castle' => 'K'];
        }
        if (strpos($castle, 'Q') !== false && $board[cz_sq(3, 1)] === '' && $board[cz_sq(2, 1)] === '' && $board[cz_sq(1, 1)] === ''
            && !cz_attacked($board, cz_sq(4, 1), $opp) && !cz_attacked($board, cz_sq(3, 1), $opp) && !cz_attacked($board, cz_sq(2, 1), $opp)) {
            $moves[] = ['from' => $kingSq, 'to' => cz_sq(2, 1), 'promo' => '', 'castle' => 'Q'];
        }
    } elseif ($side === 'b' && $kingSq === cz_sq(4, 8)) {
        if (strpos($castle, 'k') !== false && $board[cz_sq(5, 8)] === '' && $board[cz_sq(6, 8)] === ''
            && !cz_attacked($board, cz_sq(4, 8), $opp) && !cz_attacked($board, cz_sq(5, 8), $opp) && !cz_attacked($board, cz_sq(6, 8), $opp)) {
            $moves[] = ['from' => $kingSq, 'to' => cz_sq(6, 8), 'promo' => '', 'castle' => 'k'];
        }
        if (strpos($castle, 'q') !== false && $board[cz_sq(3, 8)] === '' && $board[cz_sq(2, 8)] === '' && $board[cz_sq(1, 8)] === ''
            && !cz_attacked($board, cz_sq(4, 8), $opp) && !cz_attacked($board, cz_sq(3, 8), $opp) && !cz_attacked($board, cz_sq(2, 8), $opp)) {
            $moves[] = ['from' => $kingSq, 'to' => cz_sq(2, 8), 'promo' => '', 'castle' => 'q'];
        }
    }
}

// Apply a pseudo-legal move descriptor to produce a new state (no legality check).
function cz_make($s, $m)
{
    $ns = $s;
    $board = $s['board'];
    $side = $s['side'];
    $from = $m['from'];
    $to = $m['to'];
    $piece = $board[$from];
    $isPawn = strtoupper($piece) === 'P';
    $capture = $board[$to] !== '';

    $board[$from] = '';
    // en passant capture removes the pawn behind the target square
    if (!empty($m['ep'])) {
        $capRank = $side === 'w' ? cz_rank($to) - 1 : cz_rank($to) + 1;
        $board[cz_sq(cz_file($to), $capRank)] = '';
        $capture = true;
    }
    // promotion
    if (!empty($m['promo'])) {
        $board[$to] = $side === 'w' ? strtoupper($m['promo']) : strtolower($m['promo']);
    } else {
        $board[$to] = $piece;
    }
    // castling: move the rook
    if (!empty($m['castle'])) {
        switch ($m['castle']) {
            case 'K':
                $board[cz_sq(5, 1)] = $board[cz_sq(7, 1)];
                $board[cz_sq(7, 1)] = '';
                break;
            case 'Q':
                $board[cz_sq(3, 1)] = $board[cz_sq(0, 1)];
                $board[cz_sq(0, 1)] = '';
                break;
            case 'k':
                $board[cz_sq(5, 8)] = $board[cz_sq(7, 8)];
                $board[cz_sq(7, 8)] = '';
                break;
            case 'q':
                $board[cz_sq(3, 8)] = $board[cz_sq(0, 8)];
                $board[cz_sq(0, 8)] = '';
                break;
        }
    }

    // castling rights
    $castle = $s['castle'] === '-' ? '' : $s['castle'];
    $strip = function ($chars) use (&$castle) {
        $castle = str_replace($chars, '', $castle);
    };
    if ($piece === 'K')
        $strip(['K', 'Q']);
    if ($piece === 'k')
        $strip(['k', 'q']);
    // rook moved from or captured on original squares
    foreach ([$from, $to] as $sq) {
        if ($sq === cz_sq(0, 1))
            $strip(['Q']);
        if ($sq === cz_sq(7, 1))
            $strip(['K']);
        if ($sq === cz_sq(0, 8))
            $strip(['q']);
        if ($sq === cz_sq(7, 8))
            $strip(['k']);
    }

    // en passant target
    if (!empty($m['dbl'])) {
        $midRank = $side === 'w' ? cz_rank($from) + 1 : cz_rank($from) - 1;
        $ns['ep'] = cz_sq_to_alg(cz_sq(cz_file($from), $midRank));
    } else {
        $ns['ep'] = '-';
    }

    $ns['board'] = $board;
    $ns['side'] = $side === 'w' ? 'b' : 'w';
    $ns['castle'] = ($castle === '' ? '-' : $castle);
    $ns['half'] = ($isPawn || $capture) ? 0 : (intval($s['half']) + 1);
    $ns['full'] = intval($s['full']) + ($side === 'b' ? 1 : 0);
    return $ns;
}

// All fully-legal moves (own king not left in check).
function cz_legal_moves($s)
{
    $side = $s['side'];
    $out = [];
    foreach (cz_pseudo_moves($s) as $m) {
        $ns = cz_make($s, $m);
        if (!cz_in_check(['board' => $ns['board']] + $ns, $side))
            $out[] = $m;
    }
    return $out;
}

// Validate and apply a from/to(/promo) move. Returns [newState, meta] or null.
function cz_apply_move($state, $from, $to, $promo)
{
    $fromSq = cz_alg_to_sq($from);
    $toSq = cz_alg_to_sq($to);
    if ($fromSq < 0 || $toSq < 0)
        return null;
    $promo = is_string($promo) ? strtolower($promo) : '';
    if ($promo !== '' && strpos('qrbn', $promo) === false)
        $promo = '';

    $chosen = null;
    foreach (cz_legal_moves($state) as $m) {
        if ($m['from'] !== $fromSq || $m['to'] !== $toSq)
            continue;
        if ($m['promo'] !== '') {
            // promotion move: match requested piece (default queen)
            if ($m['promo'] === ($promo !== '' ? $promo : 'q')) {
                $chosen = $m;
                break;
            }
        } else {
            $chosen = $m;
            break;
        }
    }
    if ($chosen === null)
        return null;

    $ns = cz_make($state, $chosen);
    $moverColor = $state['side'];
    $oppColor = $ns['side'];
    $check = cz_in_check($ns, $oppColor);
    $hasMoves = count(cz_legal_moves($ns)) > 0;
    $meta = [
        'check' => $check && $hasMoves,
        'checkmate' => $check && !$hasMoves,
        'stalemate' => !$check && !$hasMoves,
        'mover' => $moverColor,
    ];
    return [$ns, $meta];
}
