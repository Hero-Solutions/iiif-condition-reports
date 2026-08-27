var Zn = Object.defineProperty;
var Wn = (t, e, n) => e in t ? Zn(t, e, { enumerable: !0, configurable: !0, writable: !0, value: n }) : t[e] = n;
var B = (t, e, n) => Wn(t, typeof e != "symbol" ? e + "" : e, n);
var Qn = /^-?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i, Ee = Math.ceil, xt = Math.floor, dt = "[BigNumber Error] ", Ve = dt + "Number primitive has more than 15 significant digits: ", $t = 1e14, j = 14, Se = 9007199254740991, $e = [1, 10, 100, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11, 1e12, 1e13], At = 1e7, lt = 1e9;
function An(t) {
  var e, n, r, i = O.prototype = { constructor: O, toString: null, valueOf: null }, s = new O(1), u = 20, o = 4, a = -7, c = 21, d = -1e7, y = 1e7, b = !1, L = 1, N = 0, M = {
    prefix: "",
    groupSize: 3,
    secondaryGroupSize: 0,
    groupSeparator: ",",
    decimalSeparator: ".",
    fractionGroupSize: 0,
    fractionGroupSeparator: " ",
    // non-breaking space
    suffix: ""
  }, k = "0123456789abcdefghijklmnopqrstuvwxyz", K = !0;
  function O(l, f) {
    var g, $, x, v, w, h, m, E, S = this;
    if (!(S instanceof O)) return new O(l, f);
    if (f == null) {
      if (l && l._isBigNumber === !0) {
        S.s = l.s, !l.c || l.e > y ? S.c = S.e = null : l.e < d ? S.c = [S.e = 0] : (S.e = l.e, S.c = l.c.slice());
        return;
      }
      if ((h = typeof l == "number") && l * 0 == 0) {
        if (S.s = 1 / l < 0 ? (l = -l, -1) : 1, l === ~~l) {
          for (v = 0, w = l; w >= 10; w /= 10, v++) ;
          v > y ? S.c = S.e = null : (S.e = v, S.c = [l]);
          return;
        }
        E = String(l);
      } else {
        if (!Qn.test(E = String(l))) return r(S, E, h);
        S.s = E.charCodeAt(0) == 45 ? (E = E.slice(1), -1) : 1;
      }
      (v = E.indexOf(".")) > -1 && (E = E.replace(".", "")), (w = E.search(/e/i)) > 0 ? (v < 0 && (v = w), v += +E.slice(w + 1), E = E.substring(0, w)) : v < 0 && (v = E.length);
    } else {
      if (st(f, 2, k.length, "Base"), f == 10 && K)
        return S = new O(l), U(S, u + S.e + 1, o);
      if (E = String(l), h = typeof l == "number") {
        if (l * 0 != 0) return r(S, E, h, f);
        if (S.s = 1 / l < 0 ? (E = E.slice(1), -1) : 1, O.DEBUG && E.replace(/^0\.0*|\./, "").length > 15)
          throw Error(Ve + l);
      } else
        S.s = E.charCodeAt(0) === 45 ? (E = E.slice(1), -1) : 1;
      for (g = k.slice(0, f), v = w = 0, m = E.length; w < m; w++)
        if (g.indexOf($ = E.charAt(w)) < 0) {
          if ($ == ".") {
            if (w > v) {
              v = m;
              continue;
            }
          } else if (!x && (E == E.toUpperCase() && (E = E.toLowerCase()) || E == E.toLowerCase() && (E = E.toUpperCase()))) {
            x = !0, w = -1, v = 0;
            continue;
          }
          return r(S, String(l), h, f);
        }
      h = !1, E = n(E, f, 10, S.s), (v = E.indexOf(".")) > -1 ? E = E.replace(".", "") : v = E.length;
    }
    for (w = 0; E.charCodeAt(w) === 48; w++) ;
    for (m = E.length; E.charCodeAt(--m) === 48; ) ;
    if (E = E.slice(w, ++m)) {
      if (m -= w, h && O.DEBUG && m > 15 && (l > Se || l !== xt(l)))
        throw Error(Ve + S.s * l);
      if ((v = v - w - 1) > y)
        S.c = S.e = null;
      else if (v < d)
        S.c = [S.e = 0];
      else {
        if (S.e = v, S.c = [], w = (v + 1) % j, v < 0 && (w += j), w < m) {
          for (w && S.c.push(+E.slice(0, w)), m -= j; w < m; )
            S.c.push(+E.slice(w, w += j));
          w = j - (E = E.slice(w)).length;
        } else
          w -= m;
        for (; w--; E += "0") ;
        S.c.push(+E);
      }
    } else
      S.c = [S.e = 0];
  }
  O.clone = An, O.ROUND_UP = 0, O.ROUND_DOWN = 1, O.ROUND_CEIL = 2, O.ROUND_FLOOR = 3, O.ROUND_HALF_UP = 4, O.ROUND_HALF_DOWN = 5, O.ROUND_HALF_EVEN = 6, O.ROUND_HALF_CEIL = 7, O.ROUND_HALF_FLOOR = 8, O.EUCLID = 9, O.config = O.set = function(l) {
    var f, g;
    if (l != null)
      if (typeof l == "object") {
        if (l.hasOwnProperty(f = "DECIMAL_PLACES") && (g = l[f], st(g, 0, lt, f), u = g), l.hasOwnProperty(f = "ROUNDING_MODE") && (g = l[f], st(g, 0, 8, f), o = g), l.hasOwnProperty(f = "EXPONENTIAL_AT") && (g = l[f], g && g.pop ? (st(g[0], -lt, 0, f), st(g[1], 0, lt, f), a = g[0], c = g[1]) : (st(g, -lt, lt, f), a = -(c = g < 0 ? -g : g))), l.hasOwnProperty(f = "RANGE"))
          if (g = l[f], g && g.pop)
            st(g[0], -lt, -1, f), st(g[1], 1, lt, f), d = g[0], y = g[1];
          else if (st(g, -lt, lt, f), g)
            d = -(y = g < 0 ? -g : g);
          else
            throw Error(dt + f + " cannot be zero: " + g);
        if (l.hasOwnProperty(f = "CRYPTO"))
          if (g = l[f], g === !!g)
            if (g)
              if (typeof crypto < "u" && crypto && (crypto.getRandomValues || crypto.randomBytes))
                b = g;
              else
                throw b = !g, Error(dt + "crypto unavailable");
            else
              b = g;
          else
            throw Error(dt + f + " not true or false: " + g);
        if (l.hasOwnProperty(f = "MODULO_MODE") && (g = l[f], st(g, 0, 9, f), L = g), l.hasOwnProperty(f = "POW_PRECISION") && (g = l[f], st(g, 0, lt, f), N = g), l.hasOwnProperty(f = "FORMAT"))
          if (g = l[f], typeof g == "object") M = g;
          else throw Error(dt + f + " not an object: " + g);
        if (l.hasOwnProperty(f = "ALPHABET"))
          if (g = l[f], typeof g == "string" && !/^.?$|[+\-.\s]|(.).*\1/.test(g))
            K = g.slice(0, 10) == "0123456789", k = g;
          else
            throw Error(dt + f + " invalid: " + g);
      } else
        throw Error(dt + "Object expected: " + l);
    return {
      DECIMAL_PLACES: u,
      ROUNDING_MODE: o,
      EXPONENTIAL_AT: [a, c],
      RANGE: [d, y],
      CRYPTO: b,
      MODULO_MODE: L,
      POW_PRECISION: N,
      FORMAT: M,
      ALPHABET: k
    };
  }, O.isBigNumber = function(l) {
    if (!l || l._isBigNumber !== !0) return !1;
    if (!O.DEBUG) return !0;
    var f, g, $ = l.c, x = l.e, v = l.s;
    t: if ({}.toString.call($) == "[object Array]") {
      if ((v === 1 || v === -1) && x >= -lt && x <= lt && x === xt(x)) {
        if ($[0] === 0) {
          if (x === 0 && $.length === 1) return !0;
          break t;
        }
        if (f = (x + 1) % j, f < 1 && (f += j), String($[0]).length == f) {
          for (f = 0; f < $.length; f++)
            if (g = $[f], g < 0 || g >= $t || g !== xt(g)) break t;
          if (g !== 0) return !0;
        }
      }
    } else if ($ === null && x === null && (v === null || v === 1 || v === -1))
      return !0;
    throw Error(dt + "Invalid BigNumber: " + l);
  }, O.maximum = O.max = function() {
    return X(arguments, -1);
  }, O.minimum = O.min = function() {
    return X(arguments, 1);
  }, O.random = (function() {
    var l = 9007199254740992, f = Math.random() * l & 2097151 ? function() {
      return xt(Math.random() * l);
    } : function() {
      return (Math.random() * 1073741824 | 0) * 8388608 + (Math.random() * 8388608 | 0);
    };
    return function(g) {
      var $, x, v, w, h, m = 0, E = [], S = new O(s);
      if (g == null ? g = u : st(g, 0, lt), w = Ee(g / j), b)
        if (crypto.getRandomValues) {
          for ($ = crypto.getRandomValues(new Uint32Array(w *= 2)); m < w; )
            h = $[m] * 131072 + ($[m + 1] >>> 11), h >= 9e15 ? (x = crypto.getRandomValues(new Uint32Array(2)), $[m] = x[0], $[m + 1] = x[1]) : (E.push(h % 1e14), m += 2);
          m = w / 2;
        } else if (crypto.randomBytes) {
          for ($ = crypto.randomBytes(w *= 7); m < w; )
            h = ($[m] & 31) * 281474976710656 + $[m + 1] * 1099511627776 + $[m + 2] * 4294967296 + $[m + 3] * 16777216 + ($[m + 4] << 16) + ($[m + 5] << 8) + $[m + 6], h >= 9e15 ? crypto.randomBytes(7).copy($, m) : (E.push(h % 1e14), m += 7);
          m = w / 7;
        } else
          throw b = !1, Error(dt + "crypto unavailable");
      if (!b)
        for (; m < w; )
          h = f(), h < 9e15 && (E[m++] = h % 1e14);
      for (w = E[--m], g %= j, w && g && (h = $e[j - g], E[m] = xt(w / h) * h); E[m] === 0; E.pop(), m--) ;
      if (m < 0)
        E = [v = 0];
      else {
        for (v = -1; E[0] === 0; E.splice(0, 1), v -= j) ;
        for (m = 1, h = E[0]; h >= 10; h /= 10, m++) ;
        m < j && (v -= j - m);
      }
      return S.e = v, S.c = E, S;
    };
  })(), O.sum = function() {
    for (var l = 1, f = arguments, g = new O(f[0]); l < f.length; ) g = g.plus(f[l++]);
    return g;
  }, n = /* @__PURE__ */ (function() {
    var l = "0123456789";
    function f(g, $, x, v) {
      for (var w, h = [0], m, E = 0, S = g.length; E < S; ) {
        for (m = h.length; m--; h[m] *= $) ;
        for (h[0] += v.indexOf(g.charAt(E++)), w = 0; w < h.length; w++)
          h[w] > x - 1 && (h[w + 1] == null && (h[w + 1] = 0), h[w + 1] += h[w] / x | 0, h[w] %= x);
      }
      return h.reverse();
    }
    return function(g, $, x, v, w) {
      var h, m, E, S, T, P, I, R, F = g.indexOf("."), Q = u, G = o;
      for (F >= 0 && (S = N, N = 0, g = g.replace(".", ""), R = new O($), P = R.pow(g.length - F), N = S, R.c = f(
        Pt(mt(P.c), P.e, "0"),
        10,
        x,
        l
      ), R.e = R.c.length), I = f(g, $, x, w ? (h = k, l) : (h = l, k)), E = S = I.length; I[--S] == 0; I.pop()) ;
      if (!I[0]) return h.charAt(0);
      if (F < 0 ? --E : (P.c = I, P.e = E, P.s = v, P = e(P, R, Q, G, x), I = P.c, T = P.r, E = P.e), m = E + Q + 1, F = I[m], S = x / 2, T = T || m < 0 || I[m + 1] != null, T = G < 4 ? (F != null || T) && (G == 0 || G == (P.s < 0 ? 3 : 2)) : F > S || F == S && (G == 4 || T || G == 6 && I[m - 1] & 1 || G == (P.s < 0 ? 8 : 7)), m < 1 || !I[0])
        g = T ? Pt(h.charAt(1), -Q, h.charAt(0)) : h.charAt(0);
      else {
        if (I.length = m, T)
          for (--x; ++I[--m] > x; )
            I[m] = 0, m || (++E, I = [1].concat(I));
        for (S = I.length; !I[--S]; ) ;
        for (F = 0, g = ""; F <= S; g += h.charAt(I[F++])) ;
        g = Pt(g, E, h.charAt(0));
      }
      return g;
    };
  })(), e = /* @__PURE__ */ (function() {
    function l($, x, v) {
      var w, h, m, E, S = 0, T = $.length, P = x % At, I = x / At | 0;
      for ($ = $.slice(); T--; )
        m = $[T] % At, E = $[T] / At | 0, w = I * m + E * P, h = P * m + w % At * At + S, S = (h / v | 0) + (w / At | 0) + I * E, $[T] = h % v;
      return S && ($ = [S].concat($)), $;
    }
    function f($, x, v, w) {
      var h, m;
      if (v != w)
        m = v > w ? 1 : -1;
      else
        for (h = m = 0; h < v; h++)
          if ($[h] != x[h]) {
            m = $[h] > x[h] ? 1 : -1;
            break;
          }
      return m;
    }
    function g($, x, v, w) {
      for (var h = 0; v--; )
        $[v] -= h, h = $[v] < x[v] ? 1 : 0, $[v] = h * w + $[v] - x[v];
      for (; !$[0] && $.length > 1; $.splice(0, 1)) ;
    }
    return function($, x, v, w, h) {
      var m, E, S, T, P, I, R, F, Q, G, W, it, yt, Mt, at, ft, gt, ht = $.s == x.s ? 1 : -1, et = $.c, tt = x.c;
      if (!et || !et[0] || !tt || !tt[0])
        return new O(
          // Return NaN if either NaN, or both Infinity or 0.
          !$.s || !x.s || (et ? tt && et[0] == tt[0] : !tt) ? NaN : (
            // Return ±0 if x is ±0 or y is ±Infinity, or return ±Infinity as y is ±0.
            et && et[0] == 0 || !tt ? ht * 0 : ht / 0
          )
        );
      for (F = new O(ht), Q = F.c = [], E = $.e - x.e, ht = v + E + 1, h || (h = $t, E = wt($.e / j) - wt(x.e / j), ht = ht / j | 0), S = 0; tt[S] == (et[S] || 0); S++) ;
      if (tt[S] > (et[S] || 0) && E--, ht < 0)
        Q.push(1), T = !0;
      else {
        for (Mt = et.length, ft = tt.length, S = 0, ht += 2, P = xt(h / (tt[0] + 1)), P > 1 && (tt = l(tt, P, h), et = l(et, P, h), ft = tt.length, Mt = et.length), yt = ft, G = et.slice(0, ft), W = G.length; W < ft; G[W++] = 0) ;
        gt = tt.slice(), gt = [0].concat(gt), at = tt[0], tt[1] >= h / 2 && at++;
        do {
          if (P = 0, m = f(tt, G, ft, W), m < 0) {
            if (it = G[0], ft != W && (it = it * h + (G[1] || 0)), P = xt(it / at), P > 1)
              for (P >= h && (P = h - 1), I = l(tt, P, h), R = I.length, W = G.length; f(I, G, R, W) == 1; )
                P--, g(I, ft < R ? gt : tt, R, h), R = I.length, m = 1;
            else
              P == 0 && (m = P = 1), I = tt.slice(), R = I.length;
            if (R < W && (I = [0].concat(I)), g(G, I, W, h), W = G.length, m == -1)
              for (; f(tt, G, ft, W) < 1; )
                P++, g(G, ft < W ? gt : tt, W, h), W = G.length;
          } else m === 0 && (P++, G = [0]);
          Q[S++] = P, G[0] ? G[W++] = et[yt] || 0 : (G = [et[yt]], W = 1);
        } while ((yt++ < Mt || G[0] != null) && ht--);
        T = G[0] != null, Q[0] || Q.splice(0, 1);
      }
      if (h == $t) {
        for (S = 1, ht = Q[0]; ht >= 10; ht /= 10, S++) ;
        U(F, v + (F.e = S + E * j - 1) + 1, w, T);
      } else
        F.e = E, F.r = +T;
      return F;
    };
  })();
  function A(l, f, g, $) {
    var x, v, w, h, m;
    if (g == null ? g = o : st(g, 0, 8), !l.c) return l.toString();
    if (x = l.c[0], w = l.e, f == null)
      m = mt(l.c), m = $ == 1 || $ == 2 && (w <= a || w >= c) ? ue(m, w) : Pt(m, w, "0");
    else if (l = U(new O(l), f, g), v = l.e, m = mt(l.c), h = m.length, $ == 1 || $ == 2 && (f <= v || v <= a)) {
      for (; h < f; m += "0", h++) ;
      m = ue(m, v);
    } else if (f -= w + ($ === 2 && v > w), m = Pt(m, v, "0"), v + 1 > h) {
      if (--f > 0) for (m += "."; f--; m += "0") ;
    } else if (f += v - h, f > 0)
      for (v + 1 == h && (m += "."); f--; m += "0") ;
    return l.s < 0 && x ? "-" + m : m;
  }
  function X(l, f) {
    for (var g, $, x = 1, v = new O(l[0]); x < l.length; x++)
      $ = new O(l[x]), (!$.s || (g = kt(v, $)) === f || g === 0 && v.s === f) && (v = $);
    return v;
  }
  function q(l, f, g) {
    for (var $ = 1, x = f.length; !f[--x]; f.pop()) ;
    for (x = f[0]; x >= 10; x /= 10, $++) ;
    return (g = $ + g * j - 1) > y ? l.c = l.e = null : g < d ? l.c = [l.e = 0] : (l.e = g, l.c = f), l;
  }
  r = /* @__PURE__ */ (function() {
    var l = /^(-?)0([xbo])(?=\w[\w.]*$)/i, f = /^([^.]+)\.$/, g = /^\.([^.]+)$/, $ = /^-?(Infinity|NaN)$/, x = /^\s*\+(?=[\w.])|^\s+|\s+$/g;
    return function(v, w, h, m) {
      var E, S = h ? w : w.replace(x, "");
      if ($.test(S))
        v.s = isNaN(S) ? null : S < 0 ? -1 : 1;
      else {
        if (!h && (S = S.replace(l, function(T, P, I) {
          return E = (I = I.toLowerCase()) == "x" ? 16 : I == "b" ? 2 : 8, !m || m == E ? P : T;
        }), m && (E = m, S = S.replace(f, "$1").replace(g, "0.$1")), w != S))
          return new O(S, E);
        if (O.DEBUG)
          throw Error(dt + "Not a" + (m ? " base " + m : "") + " number: " + w);
        v.s = null;
      }
      v.c = v.e = null;
    };
  })();
  function U(l, f, g, $) {
    var x, v, w, h, m, E, S, T = l.c, P = $e;
    if (T) {
      t: {
        for (x = 1, h = T[0]; h >= 10; h /= 10, x++) ;
        if (v = f - x, v < 0)
          v += j, w = f, m = T[E = 0], S = xt(m / P[x - w - 1] % 10);
        else if (E = Ee((v + 1) / j), E >= T.length)
          if ($) {
            for (; T.length <= E; T.push(0)) ;
            m = S = 0, x = 1, v %= j, w = v - j + 1;
          } else
            break t;
        else {
          for (m = h = T[E], x = 1; h >= 10; h /= 10, x++) ;
          v %= j, w = v - j + x, S = w < 0 ? 0 : xt(m / P[x - w - 1] % 10);
        }
        if ($ = $ || f < 0 || // Are there any non-zero digits after the rounding digit?
        // The expression  n % pows10[d - j - 1]  returns all digits of n to the right
        // of the digit at j, e.g. if n is 908714 and j is 2, the expression gives 714.
        T[E + 1] != null || (w < 0 ? m : m % P[x - w - 1]), $ = g < 4 ? (S || $) && (g == 0 || g == (l.s < 0 ? 3 : 2)) : S > 5 || S == 5 && (g == 4 || $ || g == 6 && // Check whether the digit to the left of the rounding digit is odd.
        (v > 0 ? w > 0 ? m / P[x - w] : 0 : T[E - 1]) % 10 & 1 || g == (l.s < 0 ? 8 : 7)), f < 1 || !T[0])
          return T.length = 0, $ ? (f -= l.e + 1, T[0] = P[(j - f % j) % j], l.e = -f || 0) : T[0] = l.e = 0, l;
        if (v == 0 ? (T.length = E, h = 1, E--) : (T.length = E + 1, h = P[j - v], T[E] = w > 0 ? xt(m / P[x - w] % P[w]) * h : 0), $)
          for (; ; )
            if (E == 0) {
              for (v = 1, w = T[0]; w >= 10; w /= 10, v++) ;
              for (w = T[0] += h, h = 1; w >= 10; w /= 10, h++) ;
              v != h && (l.e++, T[0] == $t && (T[0] = 1));
              break;
            } else {
              if (T[E] += h, T[E] != $t) break;
              T[E--] = 0, h = 1;
            }
        for (v = T.length; T[--v] === 0; T.pop()) ;
      }
      l.e > y ? l.c = l.e = null : l.e < d && (l.c = [l.e = 0]);
    }
    return l;
  }
  function C(l) {
    var f, g = l.e;
    return g === null ? l.toString() : (f = mt(l.c), f = g <= a || g >= c ? ue(f, g) : Pt(f, g, "0"), l.s < 0 ? "-" + f : f);
  }
  return i.absoluteValue = i.abs = function() {
    var l = new O(this);
    return l.s < 0 && (l.s = 1), l;
  }, i.comparedTo = function(l, f) {
    return kt(this, new O(l, f));
  }, i.decimalPlaces = i.dp = function(l, f) {
    var g, $, x, v = this;
    if (l != null)
      return st(l, 0, lt), f == null ? f = o : st(f, 0, 8), U(new O(v), l + v.e + 1, f);
    if (!(g = v.c)) return null;
    if ($ = ((x = g.length - 1) - wt(this.e / j)) * j, x = g[x]) for (; x % 10 == 0; x /= 10, $--) ;
    return $ < 0 && ($ = 0), $;
  }, i.dividedBy = i.div = function(l, f) {
    return e(this, new O(l, f), u, o);
  }, i.dividedToIntegerBy = i.idiv = function(l, f) {
    return e(this, new O(l, f), 0, 1);
  }, i.exponentiatedBy = i.pow = function(l, f) {
    var g, $, x, v, w, h, m, E, S, T = this;
    if (l = new O(l), l.c && !l.isInteger())
      throw Error(dt + "Exponent not an integer: " + C(l));
    if (f != null && (f = new O(f)), h = l.e > 14, !T.c || !T.c[0] || T.c[0] == 1 && !T.e && T.c.length == 1 || !l.c || !l.c[0])
      return S = new O(Math.pow(+C(T), h ? l.s * (2 - ce(l)) : +C(l))), f ? S.mod(f) : S;
    if (m = l.s < 0, f) {
      if (f.c ? !f.c[0] : !f.s) return new O(NaN);
      $ = !m && T.isInteger() && f.isInteger(), $ && (T = T.mod(f));
    } else {
      if (l.e > 9 && (T.e > 0 || T.e < -1 || (T.e == 0 ? T.c[0] > 1 || h && T.c[1] >= 24e7 : T.c[0] < 8e13 || h && T.c[0] <= 9999975e7)))
        return v = T.s < 0 && ce(l) ? -0 : 0, T.e > -1 && (v = 1 / v), new O(m ? 1 / v : v);
      N && (v = Ee(N / j + 2));
    }
    for (h ? (g = new O(0.5), m && (l.s = 1), E = ce(l)) : (x = Math.abs(+C(l)), E = x % 2), S = new O(s); ; ) {
      if (E) {
        if (S = S.times(T), !S.c) break;
        v ? S.c.length > v && (S.c.length = v) : $ && (S = S.mod(f));
      }
      if (x) {
        if (x = xt(x / 2), x === 0) break;
        E = x % 2;
      } else if (l = l.times(g), U(l, l.e + 1, 1), l.e > 14)
        E = ce(l);
      else {
        if (x = +C(l), x === 0) break;
        E = x % 2;
      }
      T = T.times(T), v ? T.c && T.c.length > v && (T.c.length = v) : $ && (T = T.mod(f));
    }
    return $ ? S : (m && (S = s.div(S)), f ? S.mod(f) : v ? U(S, N, o, w) : S);
  }, i.integerValue = function(l) {
    var f = new O(this);
    return l == null ? l = o : st(l, 0, 8), U(f, f.e + 1, l);
  }, i.isEqualTo = i.eq = function(l, f) {
    return kt(this, new O(l, f)) === 0;
  }, i.isFinite = function() {
    return !!this.c;
  }, i.isGreaterThan = i.gt = function(l, f) {
    return kt(this, new O(l, f)) > 0;
  }, i.isGreaterThanOrEqualTo = i.gte = function(l, f) {
    return (f = kt(this, new O(l, f))) === 1 || f === 0;
  }, i.isInteger = function() {
    return !!this.c && wt(this.e / j) > this.c.length - 2;
  }, i.isLessThan = i.lt = function(l, f) {
    return kt(this, new O(l, f)) < 0;
  }, i.isLessThanOrEqualTo = i.lte = function(l, f) {
    return (f = kt(this, new O(l, f))) === -1 || f === 0;
  }, i.isNaN = function() {
    return !this.s;
  }, i.isNegative = function() {
    return this.s < 0;
  }, i.isPositive = function() {
    return this.s > 0;
  }, i.isZero = function() {
    return !!this.c && this.c[0] == 0;
  }, i.minus = function(l, f) {
    var g, $, x, v, w = this, h = w.s;
    if (l = new O(l, f), f = l.s, !h || !f) return new O(NaN);
    if (h != f)
      return l.s = -f, w.plus(l);
    var m = w.e / j, E = l.e / j, S = w.c, T = l.c;
    if (!m || !E) {
      if (!S || !T) return S ? (l.s = -f, l) : new O(T ? w : NaN);
      if (!S[0] || !T[0])
        return T[0] ? (l.s = -f, l) : new O(S[0] ? w : (
          // IEEE 754 (2008) 6.3: n - n = -0 when rounding to -Infinity
          o == 3 ? -0 : 0
        ));
    }
    if (m = wt(m), E = wt(E), S = S.slice(), h = m - E) {
      for ((v = h < 0) ? (h = -h, x = S) : (E = m, x = T), x.reverse(), f = h; f--; x.push(0)) ;
      x.reverse();
    } else
      for ($ = (v = (h = S.length) < (f = T.length)) ? h : f, h = f = 0; f < $; f++)
        if (S[f] != T[f]) {
          v = S[f] < T[f];
          break;
        }
    if (v && (x = S, S = T, T = x, l.s = -l.s), f = ($ = T.length) - (g = S.length), f > 0) for (; f--; S[g++] = 0) ;
    for (f = $t - 1; $ > h; ) {
      if (S[--$] < T[$]) {
        for (g = $; g && !S[--g]; S[g] = f) ;
        --S[g], S[$] += $t;
      }
      S[$] -= T[$];
    }
    for (; S[0] == 0; S.splice(0, 1), --E) ;
    return S[0] ? q(l, S, E) : (l.s = o == 3 ? -1 : 1, l.c = [l.e = 0], l);
  }, i.modulo = i.mod = function(l, f) {
    var g, $, x = this;
    return l = new O(l, f), !x.c || !l.s || l.c && !l.c[0] ? new O(NaN) : !l.c || x.c && !x.c[0] ? new O(x) : (L == 9 ? ($ = l.s, l.s = 1, g = e(x, l, 0, 3), l.s = $, g.s *= $) : g = e(x, l, 0, L), l = x.minus(g.times(l)), !l.c[0] && L == 1 && (l.s = x.s), l);
  }, i.multipliedBy = i.times = function(l, f) {
    var g, $, x, v, w, h, m, E, S, T, P, I, R, F, Q, G = this, W = G.c, it = (l = new O(l, f)).c;
    if (!W || !it || !W[0] || !it[0])
      return !G.s || !l.s || W && !W[0] && !it || it && !it[0] && !W ? l.c = l.e = l.s = null : (l.s *= G.s, !W || !it ? l.c = l.e = null : (l.c = [0], l.e = 0)), l;
    for ($ = wt(G.e / j) + wt(l.e / j), l.s *= G.s, m = W.length, T = it.length, m < T && (R = W, W = it, it = R, x = m, m = T, T = x), x = m + T, R = []; x--; R.push(0)) ;
    for (F = $t, Q = At, x = T; --x >= 0; ) {
      for (g = 0, P = it[x] % Q, I = it[x] / Q | 0, w = m, v = x + w; v > x; )
        E = W[--w] % Q, S = W[w] / Q | 0, h = I * E + S * P, E = P * E + h % Q * Q + R[v] + g, g = (E / F | 0) + (h / Q | 0) + I * S, R[v--] = E % F;
      R[v] = g;
    }
    return g ? ++$ : R.splice(0, 1), q(l, R, $);
  }, i.negated = function() {
    var l = new O(this);
    return l.s = -l.s || null, l;
  }, i.plus = function(l, f) {
    var g, $ = this, x = $.s;
    if (l = new O(l, f), f = l.s, !x || !f) return new O(NaN);
    if (x != f)
      return l.s = -f, $.minus(l);
    var v = $.e / j, w = l.e / j, h = $.c, m = l.c;
    if (!v || !w) {
      if (!h || !m) return new O(x / 0);
      if (!h[0] || !m[0]) return m[0] ? l : new O(h[0] ? $ : x * 0);
    }
    if (v = wt(v), w = wt(w), h = h.slice(), x = v - w) {
      for (x > 0 ? (w = v, g = m) : (x = -x, g = h), g.reverse(); x--; g.push(0)) ;
      g.reverse();
    }
    for (x = h.length, f = m.length, x - f < 0 && (g = m, m = h, h = g, f = x), x = 0; f; )
      x = (h[--f] = h[f] + m[f] + x) / $t | 0, h[f] = $t === h[f] ? 0 : h[f] % $t;
    return x && (h = [x].concat(h), ++w), q(l, h, w);
  }, i.precision = i.sd = function(l, f) {
    var g, $, x, v = this;
    if (l != null && l !== !!l)
      return st(l, 1, lt), f == null ? f = o : st(f, 0, 8), U(new O(v), l, f);
    if (!(g = v.c)) return null;
    if (x = g.length - 1, $ = x * j + 1, x = g[x]) {
      for (; x % 10 == 0; x /= 10, $--) ;
      for (x = g[0]; x >= 10; x /= 10, $++) ;
    }
    return l && v.e + 1 > $ && ($ = v.e + 1), $;
  }, i.shiftedBy = function(l) {
    return st(l, -Se, Se), this.times("1e" + l);
  }, i.squareRoot = i.sqrt = function() {
    var l, f, g, $, x, v = this, w = v.c, h = v.s, m = v.e, E = u + 4, S = new O("0.5");
    if (h !== 1 || !w || !w[0])
      return new O(!h || h < 0 && (!w || w[0]) ? NaN : w ? v : 1 / 0);
    if (h = Math.sqrt(+C(v)), h == 0 || h == 1 / 0 ? (f = mt(w), (f.length + m) % 2 == 0 && (f += "0"), h = Math.sqrt(+f), m = wt((m + 1) / 2) - (m < 0 || m % 2), h == 1 / 0 ? f = "5e" + m : (f = h.toExponential(), f = f.slice(0, f.indexOf("e") + 1) + m), g = new O(f)) : g = new O(h + ""), g.c[0]) {
      for (m = g.e, h = m + E, h < 3 && (h = 0); ; )
        if (x = g, g = S.times(x.plus(e(v, x, E, 1))), mt(x.c).slice(0, h) === (f = mt(g.c)).slice(0, h))
          if (g.e < m && --h, f = f.slice(h - 3, h + 1), f == "9999" || !$ && f == "4999") {
            if (!$ && (U(x, x.e + u + 2, 0), x.times(x).eq(v))) {
              g = x;
              break;
            }
            E += 4, h += 4, $ = 1;
          } else {
            (!+f || !+f.slice(1) && f.charAt(0) == "5") && (U(g, g.e + u + 2, 1), l = !g.times(g).eq(v));
            break;
          }
    }
    return U(g, g.e + u + 1, o, l);
  }, i.toExponential = function(l, f) {
    return l != null && (st(l, 0, lt), l++), A(this, l, f, 1);
  }, i.toFixed = function(l, f) {
    return l != null && (st(l, 0, lt), l = l + this.e + 1), A(this, l, f);
  }, i.toFormat = function(l, f, g) {
    var $, x = this;
    if (g == null)
      l != null && f && typeof f == "object" ? (g = f, f = null) : l && typeof l == "object" ? (g = l, l = f = null) : g = M;
    else if (typeof g != "object")
      throw Error(dt + "Argument not an object: " + g);
    if ($ = x.toFixed(l, f), x.c) {
      var v, w = $.split("."), h = +g.groupSize, m = +g.secondaryGroupSize, E = g.groupSeparator || "", S = w[0], T = w[1], P = x.s < 0, I = P ? S.slice(1) : S, R = I.length;
      if (m && (v = h, h = m, m = v, R -= v), h > 0 && R > 0) {
        for (v = R % h || h, S = I.substr(0, v); v < R; v += h) S += E + I.substr(v, h);
        m > 0 && (S += E + I.slice(v)), P && (S = "-" + S);
      }
      $ = T ? S + (g.decimalSeparator || "") + ((m = +g.fractionGroupSize) ? T.replace(
        new RegExp("\\d{" + m + "}\\B", "g"),
        "$&" + (g.fractionGroupSeparator || "")
      ) : T) : S;
    }
    return (g.prefix || "") + $ + (g.suffix || "");
  }, i.toFraction = function(l) {
    var f, g, $, x, v, w, h, m, E, S, T, P, I = this, R = I.c;
    if (l != null && (h = new O(l), !h.isInteger() && (h.c || h.s !== 1) || h.lt(s)))
      throw Error(dt + "Argument " + (h.isInteger() ? "out of range: " : "not an integer: ") + C(h));
    if (!R) return new O(I);
    for (f = new O(s), E = g = new O(s), $ = m = new O(s), P = mt(R), v = f.e = P.length - I.e - 1, f.c[0] = $e[(w = v % j) < 0 ? j + w : w], l = !l || h.comparedTo(f) > 0 ? v > 0 ? f : E : h, w = y, y = 1 / 0, h = new O(P), m.c[0] = 0; S = e(h, f, 0, 1), x = g.plus(S.times($)), x.comparedTo(l) != 1; )
      g = $, $ = x, E = m.plus(S.times(x = E)), m = x, f = h.minus(S.times(x = f)), h = x;
    return x = e(l.minus(g), $, 0, 1), m = m.plus(x.times(E)), g = g.plus(x.times($)), m.s = E.s = I.s, v = v * 2, T = e(E, $, v, o).minus(I).abs().comparedTo(
      e(m, g, v, o).minus(I).abs()
    ) < 1 ? [E, $] : [m, g], y = w, T;
  }, i.toNumber = function() {
    return +C(this);
  }, i.toPrecision = function(l, f) {
    return l != null && st(l, 1, lt), A(this, l, f, 2);
  }, i.toString = function(l) {
    var f, g = this, $ = g.s, x = g.e;
    return x === null ? $ ? (f = "Infinity", $ < 0 && (f = "-" + f)) : f = "NaN" : (l == null ? f = x <= a || x >= c ? ue(mt(g.c), x) : Pt(mt(g.c), x, "0") : l === 10 && K ? (g = U(new O(g), u + x + 1, o), f = Pt(mt(g.c), g.e, "0")) : (st(l, 2, k.length, "Base"), f = n(Pt(mt(g.c), x, "0"), 10, l, $, !0)), $ < 0 && g.c[0] && (f = "-" + f)), f;
  }, i.valueOf = i.toJSON = function() {
    return C(this);
  }, i._isBigNumber = !0, i[Symbol.toStringTag] = "BigNumber", i[Symbol.for("nodejs.util.inspect.custom")] = i.valueOf, t != null && O.set(t), O;
}
function wt(t) {
  var e = t | 0;
  return t > 0 || t === e ? e : e - 1;
}
function mt(t) {
  for (var e, n, r = 1, i = t.length, s = t[0] + ""; r < i; ) {
    for (e = t[r++] + "", n = j - e.length; n--; e = "0" + e) ;
    s += e;
  }
  for (i = s.length; s.charCodeAt(--i) === 48; ) ;
  return s.slice(0, i + 1 || 1);
}
function kt(t, e) {
  var n, r, i = t.c, s = e.c, u = t.s, o = e.s, a = t.e, c = e.e;
  if (!u || !o) return null;
  if (n = i && !i[0], r = s && !s[0], n || r) return n ? r ? 0 : -o : u;
  if (u != o) return u;
  if (n = u < 0, r = a == c, !i || !s) return r ? 0 : !i ^ n ? 1 : -1;
  if (!r) return a > c ^ n ? 1 : -1;
  for (o = (a = i.length) < (c = s.length) ? a : c, u = 0; u < o; u++) if (i[u] != s[u]) return i[u] > s[u] ^ n ? 1 : -1;
  return a == c ? 0 : a > c ^ n ? 1 : -1;
}
function st(t, e, n, r) {
  if (t < e || t > n || t !== xt(t))
    throw Error(dt + (r || "Argument") + (typeof t == "number" ? t < e || t > n ? " out of range: " : " not an integer: " : " not a primitive number: ") + String(t));
}
function ce(t) {
  var e = t.c.length - 1;
  return wt(t.e / j) == e && t.c[e] % 2 != 0;
}
function ue(t, e) {
  return (t.length > 1 ? t.charAt(0) + "." + t.slice(1) : t) + (e < 0 ? "e" : "e+") + e;
}
function Pt(t, e, n) {
  var r, i;
  if (e < 0) {
    for (i = n + "."; ++e; i += n) ;
    t = i + t;
  } else if (r = t.length, ++e > r) {
    for (i = n, e -= r; --e; i += n) ;
    t += i;
  } else e < r && (t = t.slice(0, e) + "." + t.slice(e));
  return t;
}
var Ot = An(), zn = class {
  constructor(t) {
    B(this, "key");
    B(this, "left", null);
    B(this, "right", null);
    this.key = t;
  }
}, Wt = class extends zn {
  constructor(t) {
    super(t);
  }
}, Jn = class {
  constructor() {
    B(this, "size", 0);
    B(this, "modificationCount", 0);
    B(this, "splayCount", 0);
  }
  splay(t) {
    const e = this.root;
    if (e == null)
      return this.compare(t, t), -1;
    let n = null, r = null, i = null, s = null, u = e;
    const o = this.compare;
    let a;
    for (; ; )
      if (a = o(u.key, t), a > 0) {
        let c = u.left;
        if (c == null || (a = o(c.key, t), a > 0 && (u.left = c.right, c.right = u, u = c, c = u.left, c == null)))
          break;
        n == null ? r = u : n.left = u, n = u, u = c;
      } else if (a < 0) {
        let c = u.right;
        if (c == null || (a = o(c.key, t), a < 0 && (u.right = c.left, c.left = u, u = c, c = u.right, c == null)))
          break;
        i == null ? s = u : i.right = u, i = u, u = c;
      } else
        break;
    return i != null && (i.right = u.left, u.left = s), n != null && (n.left = u.right, u.right = r), this.root !== u && (this.root = u, this.splayCount++), a;
  }
  splayMin(t) {
    let e = t, n = e.left;
    for (; n != null; ) {
      const r = n;
      e.left = r.right, r.right = e, e = r, n = e.left;
    }
    return e;
  }
  splayMax(t) {
    let e = t, n = e.right;
    for (; n != null; ) {
      const r = n;
      e.right = r.left, r.left = e, e = r, n = e.right;
    }
    return e;
  }
  _delete(t) {
    if (this.root == null || this.splay(t) != 0) return null;
    let n = this.root;
    const r = n, i = n.left;
    if (this.size--, i == null)
      this.root = n.right;
    else {
      const s = n.right;
      n = this.splayMax(i), n.right = s, this.root = n;
    }
    return this.modificationCount++, r;
  }
  addNewRoot(t, e) {
    this.size++, this.modificationCount++;
    const n = this.root;
    if (n == null) {
      this.root = t;
      return;
    }
    e < 0 ? (t.left = n, t.right = n.right, n.right = null) : (t.right = n, t.left = n.left, n.left = null), this.root = t;
  }
  _first() {
    const t = this.root;
    return t == null ? null : (this.root = this.splayMin(t), this.root);
  }
  _last() {
    const t = this.root;
    return t == null ? null : (this.root = this.splayMax(t), this.root);
  }
  clear() {
    this.root = null, this.size = 0, this.modificationCount++;
  }
  has(t) {
    return this.validKey(t) && this.splay(t) == 0;
  }
  defaultCompare() {
    return (t, e) => t < e ? -1 : t > e ? 1 : 0;
  }
  wrap() {
    return {
      getRoot: () => this.root,
      setRoot: (t) => {
        this.root = t;
      },
      getSize: () => this.size,
      getModificationCount: () => this.modificationCount,
      getSplayCount: () => this.splayCount,
      setSplayCount: (t) => {
        this.splayCount = t;
      },
      splay: (t) => this.splay(t),
      has: (t) => this.has(t)
    };
  }
}, In, Nn, pe = class zt extends Jn {
  constructor(n, r) {
    super();
    B(this, "root", null);
    B(this, "compare");
    B(this, "validKey");
    B(this, In, "[object Set]");
    this.compare = n ?? this.defaultCompare(), this.validKey = r ?? ((i) => i != null && i != null);
  }
  delete(n) {
    return this.validKey(n) ? this._delete(n) != null : !1;
  }
  deleteAll(n) {
    for (const r of n)
      this.delete(r);
  }
  forEach(n) {
    const r = this[Symbol.iterator]();
    let i;
    for (; i = r.next(), !i.done; )
      n(i.value, i.value, this);
  }
  add(n) {
    const r = this.splay(n);
    return r != 0 && this.addNewRoot(new Wt(n), r), this;
  }
  addAndReturn(n) {
    const r = this.splay(n);
    return r != 0 && this.addNewRoot(new Wt(n), r), this.root.key;
  }
  addAll(n) {
    for (const r of n)
      this.add(r);
  }
  isEmpty() {
    return this.root == null;
  }
  isNotEmpty() {
    return this.root != null;
  }
  single() {
    if (this.size == 0) throw "Bad state: No element";
    if (this.size > 1) throw "Bad state: Too many element";
    return this.root.key;
  }
  first() {
    if (this.size == 0) throw "Bad state: No element";
    return this._first().key;
  }
  last() {
    if (this.size == 0) throw "Bad state: No element";
    return this._last().key;
  }
  lastBefore(n) {
    if (n == null) throw "Invalid arguments(s)";
    if (this.root == null) return null;
    if (this.splay(n) < 0) return this.root.key;
    let i = this.root.left;
    if (i == null) return null;
    let s = i.right;
    for (; s != null; )
      i = s, s = i.right;
    return i.key;
  }
  firstAfter(n) {
    if (n == null) throw "Invalid arguments(s)";
    if (this.root == null) return null;
    if (this.splay(n) > 0) return this.root.key;
    let i = this.root.right;
    if (i == null) return null;
    let s = i.left;
    for (; s != null; )
      i = s, s = i.left;
    return i.key;
  }
  retainAll(n) {
    const r = new zt(this.compare, this.validKey), i = this.modificationCount;
    for (const s of n) {
      if (i != this.modificationCount)
        throw "Concurrent modification during iteration.";
      this.validKey(s) && this.splay(s) == 0 && r.add(this.root.key);
    }
    r.size != this.size && (this.root = r.root, this.size = r.size, this.modificationCount++);
  }
  lookup(n) {
    return !this.validKey(n) || this.splay(n) != 0 ? null : this.root.key;
  }
  intersection(n) {
    const r = new zt(this.compare, this.validKey);
    for (const i of this)
      n.has(i) && r.add(i);
    return r;
  }
  difference(n) {
    const r = new zt(this.compare, this.validKey);
    for (const i of this)
      n.has(i) || r.add(i);
    return r;
  }
  union(n) {
    const r = this.clone();
    return r.addAll(n), r;
  }
  clone() {
    const n = new zt(this.compare, this.validKey);
    return n.size = this.size, n.root = this.copyNode(this.root), n;
  }
  copyNode(n) {
    if (n == null) return null;
    function r(s, u) {
      let o, a;
      do {
        if (o = s.left, a = s.right, o != null) {
          const c = new Wt(o.key);
          u.left = c, r(o, c);
        }
        if (a != null) {
          const c = new Wt(a.key);
          u.right = c, s = a, u = c;
        }
      } while (a != null);
    }
    const i = new Wt(n.key);
    return r(n, i), i;
  }
  toSet() {
    return this.clone();
  }
  entries() {
    return new tr(this.wrap());
  }
  keys() {
    return this[Symbol.iterator]();
  }
  values() {
    return this[Symbol.iterator]();
  }
  [(Nn = Symbol.iterator, In = Symbol.toStringTag, Nn)]() {
    return new Yn(this.wrap());
  }
}, Rn = class {
  constructor(t) {
    B(this, "tree");
    B(this, "path", new Array());
    B(this, "modificationCount", null);
    B(this, "splayCount");
    this.tree = t, this.splayCount = t.getSplayCount();
  }
  [Symbol.iterator]() {
    return this;
  }
  next() {
    return this.moveNext() ? { done: !1, value: this.current() } : { done: !0, value: null };
  }
  current() {
    if (!this.path.length) return null;
    const t = this.path[this.path.length - 1];
    return this.getValue(t);
  }
  rebuildPath(t) {
    this.path.splice(0, this.path.length), this.tree.splay(t), this.path.push(this.tree.getRoot()), this.splayCount = this.tree.getSplayCount();
  }
  findLeftMostDescendent(t) {
    for (; t != null; )
      this.path.push(t), t = t.left;
  }
  moveNext() {
    if (this.modificationCount != this.tree.getModificationCount()) {
      if (this.modificationCount == null) {
        this.modificationCount = this.tree.getModificationCount();
        let n = this.tree.getRoot();
        for (; n != null; )
          this.path.push(n), n = n.left;
        return this.path.length > 0;
      }
      throw "Concurrent modification during iteration.";
    }
    if (!this.path.length) return !1;
    this.splayCount != this.tree.getSplayCount() && this.rebuildPath(this.path[this.path.length - 1].key);
    let t = this.path[this.path.length - 1], e = t.right;
    if (e != null) {
      for (; e != null; )
        this.path.push(e), e = e.left;
      return !0;
    }
    for (this.path.pop(); this.path.length && this.path[this.path.length - 1].right === t; )
      t = this.path.pop();
    return this.path.length > 0;
  }
}, Yn = class extends Rn {
  getValue(t) {
    return t.key;
  }
}, tr = class extends Rn {
  getValue(t) {
    return [t.key, t.key];
  }
}, _n = (t) => () => t, Re = (t) => {
  const e = t ? (n, r) => r.minus(n).abs().isLessThanOrEqualTo(t) : _n(!1);
  return (n, r) => e(n, r) ? 0 : n.comparedTo(r);
};
function er(t) {
  const e = t ? (n, r, i, s, u) => n.exponentiatedBy(2).isLessThanOrEqualTo(
    s.minus(r).exponentiatedBy(2).plus(u.minus(i).exponentiatedBy(2)).times(t)
  ) : _n(!1);
  return (n, r, i) => {
    const s = n.x, u = n.y, o = i.x, a = i.y, c = u.minus(a).times(r.x.minus(o)).minus(s.minus(o).times(r.y.minus(a)));
    return e(c, s, u, o, a) ? 0 : c.comparedTo(0);
  };
}
var nr = (t) => t, rr = (t) => {
  if (t) {
    const e = new pe(Re(t)), n = new pe(Re(t)), r = (s, u) => u.addAndReturn(s), i = (s) => ({
      x: r(s.x, e),
      y: r(s.y, n)
    });
    return i({ x: new Ot(0), y: new Ot(0) }), i;
  }
  return nr;
}, _e = (t) => ({
  set: (e) => {
    It = _e(e);
  },
  reset: () => _e(t),
  compare: Re(t),
  snap: rr(t),
  orient: er(t)
}), It = _e(), Qt = (t, e) => t.ll.x.isLessThanOrEqualTo(e.x) && e.x.isLessThanOrEqualTo(t.ur.x) && t.ll.y.isLessThanOrEqualTo(e.y) && e.y.isLessThanOrEqualTo(t.ur.y), ke = (t, e) => {
  if (e.ur.x.isLessThan(t.ll.x) || t.ur.x.isLessThan(e.ll.x) || e.ur.y.isLessThan(t.ll.y) || t.ur.y.isLessThan(e.ll.y))
    return null;
  const n = t.ll.x.isLessThan(e.ll.x) ? e.ll.x : t.ll.x, r = t.ur.x.isLessThan(e.ur.x) ? t.ur.x : e.ur.x, i = t.ll.y.isLessThan(e.ll.y) ? e.ll.y : t.ll.y, s = t.ur.y.isLessThan(e.ur.y) ? t.ur.y : e.ur.y;
  return { ll: { x: n, y: i }, ur: { x: r, y: s } };
}, ae = (t, e) => t.x.times(e.y).minus(t.y.times(e.x)), kn = (t, e) => t.x.times(e.x).plus(t.y.times(e.y)), ge = (t) => kn(t, t).sqrt(), ir = (t, e, n) => {
  const r = { x: e.x.minus(t.x), y: e.y.minus(t.y) }, i = { x: n.x.minus(t.x), y: n.y.minus(t.y) };
  return ae(i, r).div(ge(i)).div(ge(r));
}, sr = (t, e, n) => {
  const r = { x: e.x.minus(t.x), y: e.y.minus(t.y) }, i = { x: n.x.minus(t.x), y: n.y.minus(t.y) };
  return kn(i, r).div(ge(i)).div(ge(r));
}, Ke = (t, e, n) => e.y.isZero() ? null : { x: t.x.plus(e.x.div(e.y).times(n.minus(t.y))), y: n }, Ze = (t, e, n) => e.x.isZero() ? null : { x: n, y: t.y.plus(e.y.div(e.x).times(n.minus(t.x))) }, or = (t, e, n, r) => {
  if (e.x.isZero()) return Ze(n, r, t.x);
  if (r.x.isZero()) return Ze(t, e, n.x);
  if (e.y.isZero()) return Ke(n, r, t.y);
  if (r.y.isZero()) return Ke(t, e, n.y);
  const i = ae(e, r);
  if (i.isZero()) return null;
  const s = { x: n.x.minus(t.x), y: n.y.minus(t.y) }, u = ae(s, e).div(i), o = ae(s, r).div(i), a = t.x.plus(o.times(e.x)), c = n.x.plus(u.times(r.x)), d = t.y.plus(o.times(e.y)), y = n.y.plus(u.times(r.y)), b = a.plus(c).div(2), L = d.plus(y).div(2);
  return { x: b, y: L };
}, Tt = class Cn {
  // Warning: 'point' input will be modified and re-used (for performance)
  constructor(e, n) {
    B(this, "point");
    B(this, "isLeft");
    B(this, "segment");
    B(this, "otherSE");
    B(this, "consumedBy");
    e.events === void 0 ? e.events = [this] : e.events.push(this), this.point = e, this.isLeft = n;
  }
  // for ordering sweep events in the sweep event queue
  static compare(e, n) {
    const r = Cn.comparePoints(e.point, n.point);
    return r !== 0 ? r : (e.point !== n.point && e.link(n), e.isLeft !== n.isLeft ? e.isLeft ? 1 : -1 : ye.compare(e.segment, n.segment));
  }
  // for ordering points in sweep line order
  static comparePoints(e, n) {
    return e.x.isLessThan(n.x) ? -1 : e.x.isGreaterThan(n.x) ? 1 : e.y.isLessThan(n.y) ? -1 : e.y.isGreaterThan(n.y) ? 1 : 0;
  }
  link(e) {
    if (e.point === this.point)
      throw new Error("Tried to link already linked events");
    const n = e.point.events;
    for (let r = 0, i = n.length; r < i; r++) {
      const s = n[r];
      this.point.events.push(s), s.point = this.point;
    }
    this.checkForConsuming();
  }
  /* Do a pass over our linked events and check to see if any pair
   * of segments match, and should be consumed. */
  checkForConsuming() {
    const e = this.point.events.length;
    for (let n = 0; n < e; n++) {
      const r = this.point.events[n];
      if (r.segment.consumedBy === void 0)
        for (let i = n + 1; i < e; i++) {
          const s = this.point.events[i];
          s.consumedBy === void 0 && r.otherSE.point.events === s.otherSE.point.events && r.segment.consume(s.segment);
        }
    }
  }
  getAvailableLinkedEvents() {
    const e = [];
    for (let n = 0, r = this.point.events.length; n < r; n++) {
      const i = this.point.events[n];
      i !== this && !i.segment.ringOut && i.segment.isInResult() && e.push(i);
    }
    return e;
  }
  /**
   * Returns a comparator function for sorting linked events that will
   * favor the event that will give us the smallest left-side angle.
   * All ring construction starts as low as possible heading to the right,
   * so by always turning left as sharp as possible we'll get polygons
   * without uncessary loops & holes.
   *
   * The comparator function has a compute cache such that it avoids
   * re-computing already-computed values.
   */
  getLeftmostComparator(e) {
    const n = /* @__PURE__ */ new Map(), r = (i) => {
      const s = i.otherSE;
      n.set(i, {
        sine: ir(this.point, e.point, s.point),
        cosine: sr(this.point, e.point, s.point)
      });
    };
    return (i, s) => {
      n.has(i) || r(i), n.has(s) || r(s);
      const { sine: u, cosine: o } = n.get(i), { sine: a, cosine: c } = n.get(s);
      return u.isGreaterThanOrEqualTo(0) && a.isGreaterThanOrEqualTo(0) ? o.isLessThan(c) ? 1 : o.isGreaterThan(c) ? -1 : 0 : u.isLessThan(0) && a.isLessThan(0) ? o.isLessThan(c) ? -1 : o.isGreaterThan(c) ? 1 : 0 : a.isLessThan(u) ? -1 : a.isGreaterThan(u) ? 1 : 0;
    };
  }
}, lr = class Ce {
  constructor(e) {
    B(this, "events");
    B(this, "poly");
    B(this, "_isExteriorRing");
    B(this, "_enclosingRing");
    this.events = e;
    for (let n = 0, r = e.length; n < r; n++)
      e[n].segment.ringOut = this;
    this.poly = null;
  }
  /* Given the segments from the sweep line pass, compute & return a series
   * of closed rings from all the segments marked to be part of the result */
  static factory(e) {
    const n = [];
    for (let r = 0, i = e.length; r < i; r++) {
      const s = e[r];
      if (!s.isInResult() || s.ringOut) continue;
      let u = null, o = s.leftSE, a = s.rightSE;
      const c = [o], d = o.point, y = [];
      for (; u = o, o = a, c.push(o), o.point !== d; )
        for (; ; ) {
          const b = o.getAvailableLinkedEvents();
          if (b.length === 0) {
            const M = c[0].point, k = c[c.length - 1].point;
            throw new Error(
              `Unable to complete output ring starting at [${M.x}, ${M.y}]. Last matching segment found ends at [${k.x}, ${k.y}].`
            );
          }
          if (b.length === 1) {
            a = b[0].otherSE;
            break;
          }
          let L = null;
          for (let M = 0, k = y.length; M < k; M++)
            if (y[M].point === o.point) {
              L = M;
              break;
            }
          if (L !== null) {
            const M = y.splice(L)[0], k = c.splice(M.index);
            k.unshift(k[0].otherSE), n.push(new Ce(k.reverse()));
            continue;
          }
          y.push({
            index: c.length,
            point: o.point
          });
          const N = o.getLeftmostComparator(u);
          a = b.sort(N)[0].otherSE;
          break;
        }
      n.push(new Ce(c));
    }
    return n;
  }
  getGeom() {
    let e = this.events[0].point;
    const n = [e];
    for (let c = 1, d = this.events.length - 1; c < d; c++) {
      const y = this.events[c].point, b = this.events[c + 1].point;
      It.orient(y, e, b) !== 0 && (n.push(y), e = y);
    }
    if (n.length === 1) return null;
    const r = n[0], i = n[1];
    It.orient(r, e, i) === 0 && n.shift(), n.push(n[0]);
    const s = this.isExteriorRing() ? 1 : -1, u = this.isExteriorRing() ? 0 : n.length - 1, o = this.isExteriorRing() ? n.length : -1, a = [];
    for (let c = u; c != o; c += s)
      a.push([n[c].x.toNumber(), n[c].y.toNumber()]);
    return a;
  }
  isExteriorRing() {
    if (this._isExteriorRing === void 0) {
      const e = this.enclosingRing();
      this._isExteriorRing = e ? !e.isExteriorRing() : !0;
    }
    return this._isExteriorRing;
  }
  enclosingRing() {
    return this._enclosingRing === void 0 && (this._enclosingRing = this._calcEnclosingRing()), this._enclosingRing;
  }
  /* Returns the ring that encloses this one, if any */
  _calcEnclosingRing() {
    var i, s;
    let e = this.events[0];
    for (let u = 1, o = this.events.length; u < o; u++) {
      const a = this.events[u];
      Tt.compare(e, a) > 0 && (e = a);
    }
    let n = e.segment.prevInResult(), r = n ? n.prevInResult() : null;
    for (; ; ) {
      if (!n) return null;
      if (!r) return n.ringOut;
      if (r.ringOut !== n.ringOut)
        return ((i = r.ringOut) == null ? void 0 : i.enclosingRing()) !== n.ringOut ? n.ringOut : (s = n.ringOut) == null ? void 0 : s.enclosingRing();
      n = r.prevInResult(), r = n ? n.prevInResult() : null;
    }
  }
}, We = class {
  constructor(t) {
    B(this, "exteriorRing");
    B(this, "interiorRings");
    this.exteriorRing = t, t.poly = this, this.interiorRings = [];
  }
  addInterior(t) {
    this.interiorRings.push(t), t.poly = this;
  }
  getGeom() {
    const t = this.exteriorRing.getGeom();
    if (t === null) return null;
    const e = [t];
    for (let n = 0, r = this.interiorRings.length; n < r; n++) {
      const i = this.interiorRings[n].getGeom();
      i !== null && e.push(i);
    }
    return e;
  }
}, cr = class {
  constructor(t) {
    B(this, "rings");
    B(this, "polys");
    this.rings = t, this.polys = this._composePolys(t);
  }
  getGeom() {
    const t = [];
    for (let e = 0, n = this.polys.length; e < n; e++) {
      const r = this.polys[e].getGeom();
      r !== null && t.push(r);
    }
    return t;
  }
  _composePolys(t) {
    var n;
    const e = [];
    for (let r = 0, i = t.length; r < i; r++) {
      const s = t[r];
      if (!s.poly)
        if (s.isExteriorRing()) e.push(new We(s));
        else {
          const u = s.enclosingRing();
          u != null && u.poly || e.push(new We(u)), (n = u == null ? void 0 : u.poly) == null || n.addInterior(s);
        }
    }
    return e;
  }
}, ur = class {
  constructor(t, e = ye.compare) {
    B(this, "queue");
    B(this, "tree");
    B(this, "segments");
    this.queue = t, this.tree = new pe(e), this.segments = [];
  }
  process(t) {
    const e = t.segment, n = [];
    if (t.consumedBy)
      return t.isLeft ? this.queue.delete(t.otherSE) : this.tree.delete(e), n;
    t.isLeft && this.tree.add(e);
    let r = e, i = e;
    do
      r = this.tree.lastBefore(r);
    while (r != null && r.consumedBy != null);
    do
      i = this.tree.firstAfter(i);
    while (i != null && i.consumedBy != null);
    if (t.isLeft) {
      let s = null;
      if (r) {
        const o = r.getIntersection(e);
        if (o !== null && (e.isAnEndpoint(o) || (s = o), !r.isAnEndpoint(o))) {
          const a = this._splitSafely(r, o);
          for (let c = 0, d = a.length; c < d; c++)
            n.push(a[c]);
        }
      }
      let u = null;
      if (i) {
        const o = i.getIntersection(e);
        if (o !== null && (e.isAnEndpoint(o) || (u = o), !i.isAnEndpoint(o))) {
          const a = this._splitSafely(i, o);
          for (let c = 0, d = a.length; c < d; c++)
            n.push(a[c]);
        }
      }
      if (s !== null || u !== null) {
        let o = null;
        s === null ? o = u : u === null ? o = s : o = Tt.comparePoints(
          s,
          u
        ) <= 0 ? s : u, this.queue.delete(e.rightSE), n.push(e.rightSE);
        const a = e.split(o);
        for (let c = 0, d = a.length; c < d; c++)
          n.push(a[c]);
      }
      n.length > 0 ? (this.tree.delete(e), n.push(t)) : (this.segments.push(e), e.prev = r);
    } else {
      if (r && i) {
        const s = r.getIntersection(i);
        if (s !== null) {
          if (!r.isAnEndpoint(s)) {
            const u = this._splitSafely(r, s);
            for (let o = 0, a = u.length; o < a; o++)
              n.push(u[o]);
          }
          if (!i.isAnEndpoint(s)) {
            const u = this._splitSafely(i, s);
            for (let o = 0, a = u.length; o < a; o++)
              n.push(u[o]);
          }
        }
      }
      this.tree.delete(e);
    }
    return n;
  }
  /* Safely split a segment that is currently in the datastructures
   * IE - a segment other than the one that is currently being processed. */
  _splitSafely(t, e) {
    this.tree.delete(t);
    const n = t.rightSE;
    this.queue.delete(n);
    const r = t.split(e);
    return r.push(n), t.consumedBy === void 0 && this.tree.add(t), r;
  }
}, ar = class {
  constructor() {
    B(this, "type");
    B(this, "numMultiPolys");
  }
  run(t, e, n) {
    Jt.type = t;
    const r = [new ze(e, !0)];
    for (let c = 0, d = n.length; c < d; c++)
      r.push(new ze(n[c], !1));
    if (Jt.numMultiPolys = r.length, Jt.type === "difference") {
      const c = r[0];
      let d = 1;
      for (; d < r.length; )
        ke(r[d].bbox, c.bbox) !== null ? d++ : r.splice(d, 1);
    }
    if (Jt.type === "intersection")
      for (let c = 0, d = r.length; c < d; c++) {
        const y = r[c];
        for (let b = c + 1, L = r.length; b < L; b++)
          if (ke(y.bbox, r[b].bbox) === null) return [];
      }
    const i = new pe(Tt.compare);
    for (let c = 0, d = r.length; c < d; c++) {
      const y = r[c].getSweepEvents();
      for (let b = 0, L = y.length; b < L; b++)
        i.add(y[b]);
    }
    const s = new ur(i);
    let u = null;
    for (i.size != 0 && (u = i.first(), i.delete(u)); u; ) {
      const c = s.process(u);
      for (let d = 0, y = c.length; d < y; d++) {
        const b = c[d];
        b.consumedBy === void 0 && i.add(b);
      }
      i.size != 0 ? (u = i.first(), i.delete(u)) : u = null;
    }
    It.reset();
    const o = lr.factory(s.segments);
    return new cr(o).getGeom();
  }
}, Jt = new ar(), de = Jt, fr = 0, ye = class fe {
  /* Warning: a reference to ringWindings input will be stored,
   *  and possibly will be later modified */
  constructor(e, n, r, i) {
    B(this, "id");
    B(this, "leftSE");
    B(this, "rightSE");
    B(this, "rings");
    B(this, "windings");
    B(this, "ringOut");
    B(this, "consumedBy");
    B(this, "prev");
    B(this, "_prevInResult");
    B(this, "_beforeState");
    B(this, "_afterState");
    B(this, "_isInResult");
    this.id = ++fr, this.leftSE = e, e.segment = this, e.otherSE = n, this.rightSE = n, n.segment = this, n.otherSE = e, this.rings = r, this.windings = i;
  }
  /* This compare() function is for ordering segments in the sweep
   * line tree, and does so according to the following criteria:
   *
   * Consider the vertical line that lies an infinestimal step to the
   * right of the right-more of the two left endpoints of the input
   * segments. Imagine slowly moving a point up from negative infinity
   * in the increasing y direction. Which of the two segments will that
   * point intersect first? That segment comes 'before' the other one.
   *
   * If neither segment would be intersected by such a line, (if one
   * or more of the segments are vertical) then the line to be considered
   * is directly on the right-more of the two left inputs.
   */
  static compare(e, n) {
    const r = e.leftSE.point.x, i = n.leftSE.point.x, s = e.rightSE.point.x, u = n.rightSE.point.x;
    if (u.isLessThan(r)) return 1;
    if (s.isLessThan(i)) return -1;
    const o = e.leftSE.point.y, a = n.leftSE.point.y, c = e.rightSE.point.y, d = n.rightSE.point.y;
    if (r.isLessThan(i)) {
      if (a.isLessThan(o) && a.isLessThan(c)) return 1;
      if (a.isGreaterThan(o) && a.isGreaterThan(c)) return -1;
      const y = e.comparePoint(n.leftSE.point);
      if (y < 0) return 1;
      if (y > 0) return -1;
      const b = n.comparePoint(e.rightSE.point);
      return b !== 0 ? b : -1;
    }
    if (r.isGreaterThan(i)) {
      if (o.isLessThan(a) && o.isLessThan(d)) return -1;
      if (o.isGreaterThan(a) && o.isGreaterThan(d)) return 1;
      const y = n.comparePoint(e.leftSE.point);
      if (y !== 0) return y;
      const b = e.comparePoint(n.rightSE.point);
      return b < 0 ? 1 : b > 0 ? -1 : 1;
    }
    if (o.isLessThan(a)) return -1;
    if (o.isGreaterThan(a)) return 1;
    if (s.isLessThan(u)) {
      const y = n.comparePoint(e.rightSE.point);
      if (y !== 0) return y;
    }
    if (s.isGreaterThan(u)) {
      const y = e.comparePoint(n.rightSE.point);
      if (y < 0) return 1;
      if (y > 0) return -1;
    }
    if (!s.eq(u)) {
      const y = c.minus(o), b = s.minus(r), L = d.minus(a), N = u.minus(i);
      if (y.isGreaterThan(b) && L.isLessThan(N)) return 1;
      if (y.isLessThan(b) && L.isGreaterThan(N)) return -1;
    }
    return s.isGreaterThan(u) ? 1 : s.isLessThan(u) || c.isLessThan(d) ? -1 : c.isGreaterThan(d) ? 1 : e.id < n.id ? -1 : e.id > n.id ? 1 : 0;
  }
  static fromRing(e, n, r) {
    let i, s, u;
    const o = Tt.comparePoints(e, n);
    if (o < 0)
      i = e, s = n, u = 1;
    else if (o > 0)
      i = n, s = e, u = -1;
    else
      throw new Error(
        `Tried to create degenerate segment at [${e.x}, ${e.y}]`
      );
    const a = new Tt(i, !0), c = new Tt(s, !1);
    return new fe(a, c, [r], [u]);
  }
  /* When a segment is split, the rightSE is replaced with a new sweep event */
  replaceRightSE(e) {
    this.rightSE = e, this.rightSE.segment = this, this.rightSE.otherSE = this.leftSE, this.leftSE.otherSE = this.rightSE;
  }
  bbox() {
    const e = this.leftSE.point.y, n = this.rightSE.point.y;
    return {
      ll: { x: this.leftSE.point.x, y: e.isLessThan(n) ? e : n },
      ur: { x: this.rightSE.point.x, y: e.isGreaterThan(n) ? e : n }
    };
  }
  /* A vector from the left point to the right */
  vector() {
    return {
      x: this.rightSE.point.x.minus(this.leftSE.point.x),
      y: this.rightSE.point.y.minus(this.leftSE.point.y)
    };
  }
  isAnEndpoint(e) {
    return e.x.eq(this.leftSE.point.x) && e.y.eq(this.leftSE.point.y) || e.x.eq(this.rightSE.point.x) && e.y.eq(this.rightSE.point.y);
  }
  /* Compare this segment with a point.
   *
   * A point P is considered to be colinear to a segment if there
   * exists a distance D such that if we travel along the segment
   * from one * endpoint towards the other a distance D, we find
   * ourselves at point P.
   *
   * Return value indicates:
   *
   *   1: point lies above the segment (to the left of vertical)
   *   0: point is colinear to segment
   *  -1: point lies below the segment (to the right of vertical)
   */
  comparePoint(e) {
    return It.orient(this.leftSE.point, e, this.rightSE.point);
  }
  /**
   * Given another segment, returns the first non-trivial intersection
   * between the two segments (in terms of sweep line ordering), if it exists.
   *
   * A 'non-trivial' intersection is one that will cause one or both of the
   * segments to be split(). As such, 'trivial' vs. 'non-trivial' intersection:
   *
   *   * endpoint of segA with endpoint of segB --> trivial
   *   * endpoint of segA with point along segB --> non-trivial
   *   * endpoint of segB with point along segA --> non-trivial
   *   * point along segA with point along segB --> non-trivial
   *
   * If no non-trivial intersection exists, return null
   * Else, return null.
   */
  getIntersection(e) {
    const n = this.bbox(), r = e.bbox(), i = ke(n, r);
    if (i === null) return null;
    const s = this.leftSE.point, u = this.rightSE.point, o = e.leftSE.point, a = e.rightSE.point, c = Qt(n, o) && this.comparePoint(o) === 0, d = Qt(r, s) && e.comparePoint(s) === 0, y = Qt(n, a) && this.comparePoint(a) === 0, b = Qt(r, u) && e.comparePoint(u) === 0;
    if (d && c)
      return b && !y ? u : !b && y ? a : null;
    if (d)
      return y && s.x.eq(a.x) && s.y.eq(a.y) ? null : s;
    if (c)
      return b && u.x.eq(o.x) && u.y.eq(o.y) ? null : o;
    if (b && y) return null;
    if (b) return u;
    if (y) return a;
    const L = or(s, this.vector(), o, e.vector());
    return L === null || !Qt(i, L) ? null : It.snap(L);
  }
  /**
   * Split the given segment into multiple segments on the given points.
   *  * Each existing segment will retain its leftSE and a new rightSE will be
   *    generated for it.
   *  * A new segment will be generated which will adopt the original segment's
   *    rightSE, and a new leftSE will be generated for it.
   *  * If there are more than two points given to split on, new segments
   *    in the middle will be generated with new leftSE and rightSE's.
   *  * An array of the newly generated SweepEvents will be returned.
   *
   * Warning: input array of points is modified
   */
  split(e) {
    const n = [], r = e.events !== void 0, i = new Tt(e, !0), s = new Tt(e, !1), u = this.rightSE;
    this.replaceRightSE(s), n.push(s), n.push(i);
    const o = new fe(
      i,
      u,
      this.rings.slice(),
      this.windings.slice()
    );
    return Tt.comparePoints(o.leftSE.point, o.rightSE.point) > 0 && o.swapEvents(), Tt.comparePoints(this.leftSE.point, this.rightSE.point) > 0 && this.swapEvents(), r && (i.checkForConsuming(), s.checkForConsuming()), n;
  }
  /* Swap which event is left and right */
  swapEvents() {
    const e = this.rightSE;
    this.rightSE = this.leftSE, this.leftSE = e, this.leftSE.isLeft = !0, this.rightSE.isLeft = !1;
    for (let n = 0, r = this.windings.length; n < r; n++)
      this.windings[n] *= -1;
  }
  /* Consume another segment. We take their rings under our wing
   * and mark them as consumed. Use for perfectly overlapping segments */
  consume(e) {
    let n = this, r = e;
    for (; n.consumedBy; ) n = n.consumedBy;
    for (; r.consumedBy; ) r = r.consumedBy;
    const i = fe.compare(n, r);
    if (i !== 0) {
      if (i > 0) {
        const s = n;
        n = r, r = s;
      }
      if (n.prev === r) {
        const s = n;
        n = r, r = s;
      }
      for (let s = 0, u = r.rings.length; s < u; s++) {
        const o = r.rings[s], a = r.windings[s], c = n.rings.indexOf(o);
        c === -1 ? (n.rings.push(o), n.windings.push(a)) : n.windings[c] += a;
      }
      r.rings = null, r.windings = null, r.consumedBy = n, r.leftSE.consumedBy = n.leftSE, r.rightSE.consumedBy = n.rightSE;
    }
  }
  /* The first segment previous segment chain that is in the result */
  prevInResult() {
    return this._prevInResult !== void 0 ? this._prevInResult : (this.prev ? this.prev.isInResult() ? this._prevInResult = this.prev : this._prevInResult = this.prev.prevInResult() : this._prevInResult = null, this._prevInResult);
  }
  beforeState() {
    if (this._beforeState !== void 0) return this._beforeState;
    if (!this.prev)
      this._beforeState = {
        rings: [],
        windings: [],
        multiPolys: []
      };
    else {
      const e = this.prev.consumedBy || this.prev;
      this._beforeState = e.afterState();
    }
    return this._beforeState;
  }
  afterState() {
    if (this._afterState !== void 0) return this._afterState;
    const e = this.beforeState();
    this._afterState = {
      rings: e.rings.slice(0),
      windings: e.windings.slice(0),
      multiPolys: []
    };
    const n = this._afterState.rings, r = this._afterState.windings, i = this._afterState.multiPolys;
    for (let o = 0, a = this.rings.length; o < a; o++) {
      const c = this.rings[o], d = this.windings[o], y = n.indexOf(c);
      y === -1 ? (n.push(c), r.push(d)) : r[y] += d;
    }
    const s = [], u = [];
    for (let o = 0, a = n.length; o < a; o++) {
      if (r[o] === 0) continue;
      const c = n[o], d = c.poly;
      if (u.indexOf(d) === -1)
        if (c.isExterior) s.push(d);
        else {
          u.indexOf(d) === -1 && u.push(d);
          const y = s.indexOf(c.poly);
          y !== -1 && s.splice(y, 1);
        }
    }
    for (let o = 0, a = s.length; o < a; o++) {
      const c = s[o].multiPoly;
      i.indexOf(c) === -1 && i.push(c);
    }
    return this._afterState;
  }
  /* Is this segment part of the final result? */
  isInResult() {
    if (this.consumedBy) return !1;
    if (this._isInResult !== void 0) return this._isInResult;
    const e = this.beforeState().multiPolys, n = this.afterState().multiPolys;
    switch (de.type) {
      case "union": {
        const r = e.length === 0, i = n.length === 0;
        this._isInResult = r !== i;
        break;
      }
      case "intersection": {
        let r, i;
        e.length < n.length ? (r = e.length, i = n.length) : (r = n.length, i = e.length), this._isInResult = i === de.numMultiPolys && r < i;
        break;
      }
      case "xor": {
        const r = Math.abs(e.length - n.length);
        this._isInResult = r % 2 === 1;
        break;
      }
      case "difference": {
        const r = (i) => i.length === 1 && i[0].isSubject;
        this._isInResult = r(e) !== r(n);
        break;
      }
    }
    return this._isInResult;
  }
}, Qe = class {
  constructor(t, e, n) {
    B(this, "poly");
    B(this, "isExterior");
    B(this, "segments");
    B(this, "bbox");
    if (!Array.isArray(t) || t.length === 0)
      throw new Error("Input geometry is not a valid Polygon or MultiPolygon");
    if (this.poly = e, this.isExterior = n, this.segments = [], typeof t[0][0] != "number" || typeof t[0][1] != "number")
      throw new Error("Input geometry is not a valid Polygon or MultiPolygon");
    const r = It.snap({ x: new Ot(t[0][0]), y: new Ot(t[0][1]) });
    this.bbox = {
      ll: { x: r.x, y: r.y },
      ur: { x: r.x, y: r.y }
    };
    let i = r;
    for (let s = 1, u = t.length; s < u; s++) {
      if (typeof t[s][0] != "number" || typeof t[s][1] != "number")
        throw new Error("Input geometry is not a valid Polygon or MultiPolygon");
      const o = It.snap({ x: new Ot(t[s][0]), y: new Ot(t[s][1]) });
      o.x.eq(i.x) && o.y.eq(i.y) || (this.segments.push(ye.fromRing(i, o, this)), o.x.isLessThan(this.bbox.ll.x) && (this.bbox.ll.x = o.x), o.y.isLessThan(this.bbox.ll.y) && (this.bbox.ll.y = o.y), o.x.isGreaterThan(this.bbox.ur.x) && (this.bbox.ur.x = o.x), o.y.isGreaterThan(this.bbox.ur.y) && (this.bbox.ur.y = o.y), i = o);
    }
    (!r.x.eq(i.x) || !r.y.eq(i.y)) && this.segments.push(ye.fromRing(i, r, this));
  }
  getSweepEvents() {
    const t = [];
    for (let e = 0, n = this.segments.length; e < n; e++) {
      const r = this.segments[e];
      t.push(r.leftSE), t.push(r.rightSE);
    }
    return t;
  }
}, hr = class {
  constructor(t, e) {
    B(this, "multiPoly");
    B(this, "exteriorRing");
    B(this, "interiorRings");
    B(this, "bbox");
    if (!Array.isArray(t))
      throw new Error("Input geometry is not a valid Polygon or MultiPolygon");
    this.exteriorRing = new Qe(t[0], this, !0), this.bbox = {
      ll: { x: this.exteriorRing.bbox.ll.x, y: this.exteriorRing.bbox.ll.y },
      ur: { x: this.exteriorRing.bbox.ur.x, y: this.exteriorRing.bbox.ur.y }
    }, this.interiorRings = [];
    for (let n = 1, r = t.length; n < r; n++) {
      const i = new Qe(t[n], this, !1);
      i.bbox.ll.x.isLessThan(this.bbox.ll.x) && (this.bbox.ll.x = i.bbox.ll.x), i.bbox.ll.y.isLessThan(this.bbox.ll.y) && (this.bbox.ll.y = i.bbox.ll.y), i.bbox.ur.x.isGreaterThan(this.bbox.ur.x) && (this.bbox.ur.x = i.bbox.ur.x), i.bbox.ur.y.isGreaterThan(this.bbox.ur.y) && (this.bbox.ur.y = i.bbox.ur.y), this.interiorRings.push(i);
    }
    this.multiPoly = e;
  }
  getSweepEvents() {
    const t = this.exteriorRing.getSweepEvents();
    for (let e = 0, n = this.interiorRings.length; e < n; e++) {
      const r = this.interiorRings[e].getSweepEvents();
      for (let i = 0, s = r.length; i < s; i++)
        t.push(r[i]);
    }
    return t;
  }
}, ze = class {
  constructor(t, e) {
    B(this, "isSubject");
    B(this, "polys");
    B(this, "bbox");
    if (!Array.isArray(t))
      throw new Error("Input geometry is not a valid Polygon or MultiPolygon");
    try {
      typeof t[0][0][0] == "number" && (t = [t]);
    } catch {
    }
    this.polys = [], this.bbox = {
      ll: { x: new Ot(Number.POSITIVE_INFINITY), y: new Ot(Number.POSITIVE_INFINITY) },
      ur: { x: new Ot(Number.NEGATIVE_INFINITY), y: new Ot(Number.NEGATIVE_INFINITY) }
    };
    for (let n = 0, r = t.length; n < r; n++) {
      const i = new hr(t[n], this);
      i.bbox.ll.x.isLessThan(this.bbox.ll.x) && (this.bbox.ll.x = i.bbox.ll.x), i.bbox.ll.y.isLessThan(this.bbox.ll.y) && (this.bbox.ll.y = i.bbox.ll.y), i.bbox.ur.x.isGreaterThan(this.bbox.ur.x) && (this.bbox.ur.x = i.bbox.ur.x), i.bbox.ur.y.isGreaterThan(this.bbox.ur.y) && (this.bbox.ur.y = i.bbox.ur.y), this.polys.push(i);
    }
    this.isSubject = e;
  }
  getSweepEvents() {
    const t = [];
    for (let e = 0, n = this.polys.length; e < n; e++) {
      const r = this.polys[e].getSweepEvents();
      for (let i = 0, s = r.length; i < s; i++)
        t.push(r[i]);
    }
    return t;
  }
}, pr = (t, ...e) => de.run("union", t, e), gr = (t, ...e) => de.run("difference", t, e);
It.set;
var dr = Object.defineProperty, yr = (t, e, n) => e in t ? dr(t, e, { enumerable: !0, configurable: !0, writable: !0, value: n }) : t[e] = n, Je = (t, e, n) => yr(t, typeof e != "symbol" ? e + "" : e, n);
function Bt() {
}
function Be(t, e) {
  for (const n in e) t[n] = e[n];
  return (
    /** @type {T & S} */
    t
  );
}
function Bn(t) {
  return t();
}
function Ye() {
  return /* @__PURE__ */ Object.create(null);
}
function St(t) {
  t.forEach(Bn);
}
function ot(t) {
  return typeof t == "function";
}
function Xt(t, e) {
  return t != t ? e == e : t !== e || t && typeof t == "object" || typeof t == "function";
}
function mr(t) {
  return Object.keys(t).length === 0;
}
function xr(t, e, n, r) {
  if (t) {
    const i = Gn(t, e, n, r);
    return t[0](i);
  }
}
function Gn(t, e, n, r) {
  return t[1] && r ? Be(n.ctx.slice(), t[1](r(e))) : n.ctx;
}
function wr(t, e, n, r) {
  if (t[2] && r) {
    const i = t[2](r(n));
    if (e.dirty === void 0)
      return i;
    if (typeof i == "object") {
      const s = [], u = Math.max(e.dirty.length, i.length);
      for (let o = 0; o < u; o += 1)
        s[o] = e.dirty[o] | i[o];
      return s;
    }
    return e.dirty | i;
  }
  return e.dirty;
}
function vr(t, e, n, r, i, s) {
  if (i) {
    const u = Gn(e, n, r, s);
    t.p(u, i);
  }
}
function Er(t) {
  if (t.ctx.length > 32) {
    const e = [], n = t.ctx.length / 32;
    for (let r = 0; r < n; r++)
      e[r] = -1;
    return e;
  }
  return -1;
}
function tn(t) {
  const e = {};
  for (const n in t) n[0] !== "$" && (e[n] = t[n]);
  return e;
}
function en(t) {
  return t ?? "";
}
function nt(t, e) {
  t.appendChild(e);
}
function V(t, e, n) {
  t.insertBefore(e, n || null);
}
function H(t) {
  t.parentNode && t.parentNode.removeChild(t);
}
function we(t, e) {
  for (let n = 0; n < t.length; n += 1)
    t[n] && t[n].d(e);
}
function D(t) {
  return document.createElementNS("http://www.w3.org/2000/svg", t);
}
function qn(t) {
  return document.createTextNode(t);
}
function pt() {
  return qn(" ");
}
function re() {
  return qn("");
}
function J(t, e, n, r) {
  return t.addEventListener(e, n, r), () => t.removeEventListener(e, n, r);
}
function p(t, e, n) {
  n == null ? t.removeAttribute(e) : t.getAttribute(e) !== n && t.setAttribute(e, n);
}
function Sr(t) {
  return Array.from(t.childNodes);
}
function nn(t, e, n) {
  t.classList.toggle(e, !!n);
}
function $r(t, e, { bubbles: n = !1, cancelable: r = !1 } = {}) {
  return new CustomEvent(t, { detail: e, bubbles: n, cancelable: r });
}
let te;
function Yt(t) {
  te = t;
}
function Dn() {
  if (!te) throw new Error("Function called outside component initialization");
  return te;
}
function Un(t) {
  Dn().$$.on_mount.push(t);
}
function Fe() {
  const t = Dn();
  return (e, n, { cancelable: r = !1 } = {}) => {
    const i = t.$$.callbacks[e];
    if (i) {
      const s = $r(
        /** @type {string} */
        e,
        n,
        { cancelable: r }
      );
      return i.slice().forEach((u) => {
        u.call(t, s);
      }), !s.defaultPrevented;
    }
    return !0;
  };
}
function ct(t, e) {
  const n = t.$$.callbacks[e.type];
  n && n.slice().forEach((r) => r.call(this, e));
}
const Dt = [], rn = [];
let Ft = [];
const sn = [], Fn = /* @__PURE__ */ Promise.resolve();
let Ge = !1;
function jn() {
  Ge || (Ge = !0, Fn.then(Xn));
}
function Hn() {
  return jn(), Fn;
}
function qe(t) {
  Ft.push(t);
}
const be = /* @__PURE__ */ new Set();
let Gt = 0;
function Xn() {
  if (Gt !== 0)
    return;
  const t = te;
  do {
    try {
      for (; Gt < Dt.length; ) {
        const e = Dt[Gt];
        Gt++, Yt(e), br(e.$$);
      }
    } catch (e) {
      throw Dt.length = 0, Gt = 0, e;
    }
    for (Yt(null), Dt.length = 0, Gt = 0; rn.length; ) rn.pop()();
    for (let e = 0; e < Ft.length; e += 1) {
      const n = Ft[e];
      be.has(n) || (be.add(n), n());
    }
    Ft.length = 0;
  } while (Dt.length);
  for (; sn.length; )
    sn.pop()();
  Ge = !1, be.clear(), Yt(t);
}
function br(t) {
  if (t.fragment !== null) {
    t.update(), St(t.before_update);
    const e = t.dirty;
    t.dirty = [-1], t.fragment && t.fragment.p(t.ctx, e), t.after_update.forEach(qe);
  }
}
function Tr(t) {
  const e = [], n = [];
  Ft.forEach((r) => t.indexOf(r) === -1 ? e.push(r) : n.push(r)), n.forEach((r) => r()), Ft = e;
}
const he = /* @__PURE__ */ new Set();
let Ct;
function jt() {
  Ct = {
    r: 0,
    c: [],
    p: Ct
    // parent group
  };
}
function Ht() {
  Ct.r || St(Ct.c), Ct = Ct.p;
}
function Y(t, e) {
  t && t.i && (he.delete(t), t.i(e));
}
function rt(t, e, n, r) {
  if (t && t.o) {
    if (he.has(t)) return;
    he.add(t), Ct.c.push(() => {
      he.delete(t), r && (n && t.d(1), r());
    }), t.o(e);
  } else r && r();
}
function _t(t) {
  return (t == null ? void 0 : t.length) !== void 0 ? t : Array.from(t);
}
function bt(t) {
  t && t.c();
}
function vt(t, e, n) {
  const { fragment: r, after_update: i } = t.$$;
  r && r.m(e, n), qe(() => {
    const s = t.$$.on_mount.map(Bn).filter(ot);
    t.$$.on_destroy ? t.$$.on_destroy.push(...s) : St(s), t.$$.on_mount = [];
  }), i.forEach(qe);
}
function Et(t, e) {
  const n = t.$$;
  n.fragment !== null && (Tr(n.after_update), St(n.on_destroy), n.fragment && n.fragment.d(e), n.on_destroy = n.fragment = null, n.ctx = []);
}
function Or(t, e) {
  t.$$.dirty[0] === -1 && (Dt.push(t), jn(), t.$$.dirty.fill(0)), t.$$.dirty[e / 31 | 0] |= 1 << e % 31;
}
function Vt(t, e, n, r, i, s, u = null, o = [-1]) {
  const a = te;
  Yt(t);
  const c = t.$$ = {
    fragment: null,
    ctx: [],
    // state
    props: s,
    update: Bt,
    not_equal: i,
    bound: Ye(),
    // lifecycle
    on_mount: [],
    on_destroy: [],
    on_disconnect: [],
    before_update: [],
    after_update: [],
    context: new Map(e.context || (a ? a.$$.context : [])),
    // everything else
    callbacks: Ye(),
    dirty: o,
    skip_bound: !1,
    root: e.target || a.$$.root
  };
  u && u(c.root);
  let d = !1;
  if (c.ctx = n ? n(t, e.props || {}, (y, b, ...L) => {
    const N = L.length ? L[0] : b;
    return c.ctx && i(c.ctx[y], c.ctx[y] = N) && (!c.skip_bound && c.bound[y] && c.bound[y](N), d && Or(t, y)), b;
  }) : [], c.update(), d = !0, St(c.before_update), c.fragment = r ? r(c.ctx) : !1, e.target) {
    if (e.hydrate) {
      const y = Sr(e.target);
      c.fragment && c.fragment.l(y), y.forEach(H);
    } else
      c.fragment && c.fragment.c();
    e.intro && Y(t.$$.fragment), vt(t, e.target, e.anchor), Xn();
  }
  Yt(a);
}
class Kt {
  constructor() {
    Je(this, "$$"), Je(this, "$$set");
  }
  /** @returns {void} */
  $destroy() {
    Et(this, 1), this.$destroy = Bt;
  }
  /**
   * @template {Extract<keyof Events, string>} K
   * @param {K} type
   * @param {((e: Events[K]) => void) | null | undefined} callback
   * @returns {() => void}
   */
  $on(e, n) {
    if (!ot(n))
      return Bt;
    const r = this.$$.callbacks[e] || (this.$$.callbacks[e] = []);
    return r.push(n), () => {
      const i = r.indexOf(n);
      i !== -1 && r.splice(i, 1);
    };
  }
  /**
   * @param {Partial<Props>} props
   * @returns {void}
   */
  $set(e) {
    this.$$set && !mr(e) && (this.$$.skip_bound = !0, this.$$set(e), this.$$.skip_bound = !1);
  }
}
const Lr = "4";
typeof window < "u" && (window.__svelte || (window.__svelte = { v: /* @__PURE__ */ new Set() })).v.add(Lr);
var ut = /* @__PURE__ */ ((t) => (t.ELLIPSE = "ELLIPSE", t.MULTIPOLYGON = "MULTIPOLYGON", t.POLYGON = "POLYGON", t.POLYLINE = "POLYLINE", t.RECTANGLE = "RECTANGLE", t.LINE = "LINE", t))(ut || {}), Mr = { exports: {} };
(function(t) {
  (function() {
    function e(o, a) {
      var c = o.x - a.x, d = o.y - a.y;
      return c * c + d * d;
    }
    function n(o, a, c) {
      var d = a.x, y = a.y, b = c.x - d, L = c.y - y;
      if (b !== 0 || L !== 0) {
        var N = ((o.x - d) * b + (o.y - y) * L) / (b * b + L * L);
        N > 1 ? (d = c.x, y = c.y) : N > 0 && (d += b * N, y += L * N);
      }
      return b = o.x - d, L = o.y - y, b * b + L * L;
    }
    function r(o, a) {
      for (var c = o[0], d = [c], y, b = 1, L = o.length; b < L; b++)
        y = o[b], e(y, c) > a && (d.push(y), c = y);
      return c !== y && d.push(y), d;
    }
    function i(o, a, c, d, y) {
      for (var b = d, L, N = a + 1; N < c; N++) {
        var M = n(o[N], o[a], o[c]);
        M > b && (L = N, b = M);
      }
      b > d && (L - a > 1 && i(o, a, L, d, y), y.push(o[L]), c - L > 1 && i(o, L, c, d, y));
    }
    function s(o, a) {
      var c = o.length - 1, d = [o[0]];
      return i(o, 0, c, a, d), d.push(o[c]), d;
    }
    function u(o, a, c) {
      if (o.length <= 2) return o;
      var d = a !== void 0 ? a * a : 1;
      return o = c ? o : r(o, d), o = s(o, d), o;
    }
    t.exports = u, t.exports.default = u;
  })();
})(Mr);
const Zt = (t, e) => e, Lt = (t) => {
  let e = 1 / 0, n = 1 / 0, r = -1 / 0, i = -1 / 0;
  return t.forEach(([s, u]) => {
    e = Math.min(e, s), n = Math.min(n, u), r = Math.max(r, s), i = Math.max(i, u);
  }), { minX: e, minY: n, maxX: r, maxY: i };
}, me = (t) => {
  let e = 0, n = t.length - 1;
  for (let r = 0; r < t.length; r++)
    e += (t[n][0] + t[r][0]) * (t[n][1] - t[r][1]), n = r;
  return Math.abs(0.5 * e);
}, xe = (t, e, n) => {
  let r = !1;
  for (let i = 0, s = t.length - 1; i < t.length; s = i++) {
    const u = t[i][0], o = t[i][1], a = t[s][0], c = t[s][1];
    o > n != c > n && e < (a - u) * (n - o) / (c - o) + u && (r = !r);
  }
  return r;
}, Pr = (t, e = !0) => {
  let n = "M ";
  return t.forEach(([r, i], s) => {
    s === 0 ? n += `${r},${i}` : n += ` L ${r},${i}`;
  }), e && (n += " Z"), n;
}, Ir = (t, e) => {
  const n = Math.abs(e[0] - t[0]), r = Math.abs(e[1] - t[1]);
  return Math.sqrt(Math.pow(n, 2) + Math.pow(r, 2));
}, Nr = {
  area: (t) => Math.PI * t.geometry.rx * t.geometry.ry,
  intersects: (t, e, n) => {
    const { cx: r, cy: i, rx: s, ry: u } = t.geometry, o = 0, a = Math.cos(o), c = Math.sin(o), d = e - r, y = n - i, b = a * d + c * y, L = c * d - a * y;
    return b * b / (s * s) + L * L / (u * u) <= 1;
  }
};
Zt(ut.ELLIPSE, Nr);
const Ar = {
  area: (t) => 0,
  intersects: (t, e, n, r = 2) => {
    const [[i, s], [u, o]] = t.geometry.points, a = Math.abs((o - s) * e - (u - i) * n + u * s - o * i), c = Ir([i, s], [u, o]);
    return a / c <= r;
  }
};
Zt(ut.LINE, Ar);
const Rr = {
  area: (t) => {
    const { polygons: e } = t.geometry;
    return e.reduce((n, r) => {
      const [i, ...s] = r.rings, u = me(i.points), o = s.reduce((a, c) => a + me(c.points), 0);
      return n + u - o;
    }, 0);
  },
  intersects: (t, e, n) => {
    const { polygons: r } = t.geometry;
    for (const i of r) {
      const [s, ...u] = i.rings;
      if (xe(s.points, e, n)) {
        let o = !1;
        for (const a of u)
          if (xe(a.points, e, n)) {
            o = !0;
            break;
          }
        if (!o) return !0;
      }
    }
    return !1;
  }
}, Te = (t) => {
  const e = t.reduce((n, r) => [...n, ...r.rings[0].points], []);
  return Lt(e);
}, qt = (t) => t.rings.map((e) => Pr(e.points)).join(" "), _r = (t) => t.polygons.reduce((e, n) => [
  ...e,
  ...n.rings.reduce((r, i) => [...r, ...i.points], [])
], []);
Zt(ut.MULTIPOLYGON, Rr);
const kr = {
  area: (t) => {
    const e = t.geometry.points;
    return me(e);
  },
  intersects: (t, e, n) => {
    const r = t.geometry.points;
    return xe(r, e, n);
  }
};
Zt(ut.POLYGON, kr);
const Cr = {
  area: (t) => {
    const e = t.geometry;
    if (!e.closed || e.points.length < 3)
      return 0;
    const n = De(e.points, e.closed);
    return me(n);
  },
  intersects: (t, e, n, r = 2) => {
    const i = t.geometry;
    if (i.closed) {
      const s = De(i.points, i.closed);
      return xe(s, e, n);
    } else
      return Br(i, [e, n], r);
  }
}, De = (t, e = !1) => {
  const n = [];
  for (let r = 0; r < t.length; r++) {
    const i = t[r], s = t[(r + 1) % t.length];
    if (n.push(i.point), (r < t.length - 1 || e) && (i.type === "CURVE" || s.type == "CURVE")) {
      const u = Vn(
        i.point,
        i.type === "CURVE" && i.outHandle || i.point,
        s.type === "CURVE" && s.inHandle || s.point,
        s.point,
        10
        // number of approximation segments
      );
      n.push(...u.slice(1));
    }
  }
  return n;
}, Vn = (t, e, n, r, i = 10) => {
  const s = [];
  for (let u = 0; u <= i; u++) {
    const o = u / i, a = Math.pow(1 - o, 3) * t[0] + 3 * Math.pow(1 - o, 2) * o * e[0] + 3 * (1 - o) * Math.pow(o, 2) * n[0] + Math.pow(o, 3) * r[0], c = Math.pow(1 - o, 3) * t[1] + 3 * Math.pow(1 - o, 2) * o * e[1] + 3 * (1 - o) * Math.pow(o, 2) * n[1] + Math.pow(o, 3) * r[1];
    s.push([a, c]);
  }
  return s;
}, Br = (t, e, n) => {
  for (let r = 0; r < t.points.length - 1; r++) {
    const i = t.points[r], s = t.points[r + 1];
    if (i.type === "CURVE" || s.type === "CURVE") {
      const u = Vn(
        i.point,
        i.type === "CURVE" && i.outHandle || i.point,
        s.type === "CURVE" && s.inHandle || s.point,
        s.point,
        20
        // TODO make configurable? Based on scale factor? Length?
      );
      for (let o = 0; o < u.length - 1; o++)
        if (on(e, u[o], u[o + 1]) <= n) return !0;
    } else if (on(e, i.point, s.point) <= n) return !0;
  }
  return !1;
}, on = (t, e, n) => {
  const [r, i] = t, [s, u] = e, [o, a] = n, c = o - s, d = a - u, y = Math.sqrt(c * c + d * d);
  if (y === 0)
    return Math.sqrt((r - s) * (r - s) + (i - u) * (i - u));
  const b = ((r - s) * c + (i - u) * d) / (y * y);
  return b <= 0 ? Math.sqrt((r - s) * (r - s) + (i - u) * (i - u)) : b >= 1 ? Math.sqrt((r - o) * (r - o) + (i - a) * (i - a)) : Math.abs((a - u) * r - (o - s) * i + o * u - a * s) / y;
};
Zt(ut.POLYLINE, Cr);
const Gr = {
  area: (t) => t.geometry.w * t.geometry.h,
  intersects: (t, e, n) => e >= t.geometry.x && e <= t.geometry.x + t.geometry.w && n >= t.geometry.y && n <= t.geometry.y + t.geometry.h
};
Zt(ut.RECTANGLE, Gr);
const qr = [];
for (let t = 0; t < 256; ++t)
  qr.push((t + 256).toString(16).slice(1));
typeof crypto < "u" && crypto.randomUUID && crypto.randomUUID.bind(crypto);
const Dr = [];
for (let t = 0; t < 256; ++t)
  Dr.push((t + 256).toString(16).slice(1));
typeof crypto < "u" && crypto.randomUUID && crypto.randomUUID.bind(crypto);
const Ur = "useandom-26T198340PX75pxJACKVERYMINDBUSHWOLF_GQZbfghjklqvwyzrict";
let Fr = (t = 21) => {
  let e = "", n = crypto.getRandomValues(new Uint8Array(t |= 0));
  for (; t--; )
    e += Ur[n[t] & 63];
  return e;
};
Fr();
const je = (t, e = 0) => {
  const { minX: n, minY: r, maxX: i, maxY: s } = t;
  return {
    x: n - e,
    y: r - e,
    w: i - n + 2 * e,
    h: s - r + 2 * e
  };
}, Rt = typeof window > "u" || typeof navigator > "u" ? !1 : "ontouchstart" in window || navigator.maxTouchPoints > 0 || // @ts-ignore
navigator.msMaxTouchPoints > 0, jr = (t) => ({}), ln = (t) => ({ grab: (
  /*onGrab*/
  t[0]
) });
function Hr(t) {
  let e, n, r, i;
  const s = (
    /*#slots*/
    t[8].default
  ), u = xr(
    s,
    t,
    /*$$scope*/
    t[7],
    ln
  );
  return {
    c() {
      e = D("g"), u && u.c(), p(e, "class", "a9s-annotation selected");
    },
    m(o, a) {
      V(o, e, a), u && u.m(e, null), n = !0, r || (i = [
        J(
          e,
          "pointerup",
          /*onRelease*/
          t[2]
        ),
        J(
          e,
          "pointermove",
          /*onPointerMove*/
          t[1]
        )
      ], r = !0);
    },
    p(o, [a]) {
      u && u.p && (!n || a & /*$$scope*/
      128) && vr(
        u,
        s,
        o,
        /*$$scope*/
        o[7],
        n ? wr(
          s,
          /*$$scope*/
          o[7],
          a,
          jr
        ) : Er(
          /*$$scope*/
          o[7]
        ),
        ln
      );
    },
    i(o) {
      n || (Y(u, o), n = !0);
    },
    o(o) {
      rt(u, o), n = !1;
    },
    d(o) {
      o && H(e), u && u.d(o), r = !1, St(i);
    }
  };
}
function Xr(t, e, n) {
  let { $$slots: r = {}, $$scope: i } = e;
  const s = Fe();
  let { shape: u } = e, { editor: o } = e, { transform: a } = e, { svgEl: c } = e, d, y, b;
  const L = (k) => (K) => {
    if (d = k, c) {
      const { left: O, top: A } = c.getBoundingClientRect(), X = K.clientX - O, q = K.clientY - A;
      y = a.elementToImage(X, q);
    } else {
      const { offsetX: O, offsetY: A } = K;
      y = a.elementToImage(O, A);
    }
    b = u, K.target.setPointerCapture(K.pointerId), s("grab", K);
  }, N = (k) => {
    if (d) {
      const [K, O] = a.elementToImage(k.offsetX, k.offsetY), A = [K - y[0], O - y[1]];
      n(3, u = o(b, d, A)), s("change", u);
    }
  }, M = (k) => {
    k.target.releasePointerCapture(k.pointerId), d = void 0, b = u, s("release", k);
  };
  return t.$$set = (k) => {
    "shape" in k && n(3, u = k.shape), "editor" in k && n(4, o = k.editor), "transform" in k && n(5, a = k.transform), "svgEl" in k && n(6, c = k.svgEl), "$$scope" in k && n(7, i = k.$$scope);
  }, [
    L,
    N,
    M,
    u,
    o,
    a,
    c,
    i,
    r
  ];
}
class He extends Kt {
  constructor(e) {
    super(), Vt(this, e, Xr, Hr, Xt, {
      shape: 3,
      editor: 4,
      transform: 5,
      svgEl: 6
    });
  }
}
function Vr(t) {
  let e, n, r, i, s, u, o, a, c = (
    /*selected*/
    t[3] && cn(t)
  );
  return {
    c() {
      e = D("g"), n = D("circle"), c && c.c(), i = D("circle"), p(n, "class", "a9s-handle-buffer svelte-qtyc7s"), p(
        n,
        "cx",
        /*x*/
        t[0]
      ), p(
        n,
        "cy",
        /*y*/
        t[1]
      ), p(n, "r", r = /*handleRadius*/
      t[5] + 6 / /*scale*/
      t[2]), p(n, "role", "button"), p(n, "tabindex", "0"), p(i, "class", s = en(`a9s-handle-dot${/*selected*/
      t[3] ? " selected" : ""}`) + " svelte-qtyc7s"), p(
        i,
        "cx",
        /*x*/
        t[0]
      ), p(
        i,
        "cy",
        /*y*/
        t[1]
      ), p(
        i,
        "r",
        /*handleRadius*/
        t[5]
      ), p(e, "class", u = `a9s-handle ${/*$$props*/
      t[8].class || ""}`.trim());
    },
    m(d, y) {
      V(d, e, y), nt(e, n), c && c.m(e, null), nt(e, i), o || (a = [
        J(
          n,
          "dblclick",
          /*dblclick_handler_1*/
          t[12]
        ),
        J(
          n,
          "pointerenter",
          /*pointerenter_handler*/
          t[13]
        ),
        J(
          n,
          "pointerleave",
          /*pointerleave_handler*/
          t[14]
        ),
        J(
          n,
          "pointerdown",
          /*pointerdown_handler_1*/
          t[15]
        ),
        J(
          n,
          "pointerdown",
          /*onPointerDown*/
          t[6]
        ),
        J(
          n,
          "pointerup",
          /*pointerup_handler_1*/
          t[16]
        ),
        J(
          n,
          "pointerup",
          /*onPointerUp*/
          t[7]
        )
      ], o = !0);
    },
    p(d, y) {
      y & /*x*/
      1 && p(
        n,
        "cx",
        /*x*/
        d[0]
      ), y & /*y*/
      2 && p(
        n,
        "cy",
        /*y*/
        d[1]
      ), y & /*handleRadius, scale*/
      36 && r !== (r = /*handleRadius*/
      d[5] + 6 / /*scale*/
      d[2]) && p(n, "r", r), /*selected*/
      d[3] ? c ? c.p(d, y) : (c = cn(d), c.c(), c.m(e, i)) : c && (c.d(1), c = null), y & /*selected*/
      8 && s !== (s = en(`a9s-handle-dot${/*selected*/
      d[3] ? " selected" : ""}`) + " svelte-qtyc7s") && p(i, "class", s), y & /*x*/
      1 && p(
        i,
        "cx",
        /*x*/
        d[0]
      ), y & /*y*/
      2 && p(
        i,
        "cy",
        /*y*/
        d[1]
      ), y & /*handleRadius*/
      32 && p(
        i,
        "r",
        /*handleRadius*/
        d[5]
      ), y & /*$$props*/
      256 && u !== (u = `a9s-handle ${/*$$props*/
      d[8].class || ""}`.trim()) && p(e, "class", u);
    },
    d(d) {
      d && H(e), c && c.d(), o = !1, St(a);
    }
  };
}
function Kr(t) {
  let e, n, r, i, s, u, o, a, c;
  return {
    c() {
      e = D("g"), n = D("circle"), i = D("circle"), u = D("circle"), p(
        n,
        "cx",
        /*x*/
        t[0]
      ), p(
        n,
        "cy",
        /*y*/
        t[1]
      ), p(n, "r", r = /*handleRadius*/
      t[5] * 10), p(n, "class", "a9s-touch-halo"), nn(
        n,
        "touched",
        /*touched*/
        t[4]
      ), p(
        i,
        "cx",
        /*x*/
        t[0]
      ), p(
        i,
        "cy",
        /*y*/
        t[1]
      ), p(i, "r", s = /*handleRadius*/
      t[5] + 10 / /*scale*/
      t[2]), p(i, "class", "a9s-handle-buffer svelte-qtyc7s"), p(i, "role", "button"), p(i, "tabindex", "0"), p(u, "class", "a9s-handle-dot"), p(
        u,
        "cx",
        /*x*/
        t[0]
      ), p(
        u,
        "cy",
        /*y*/
        t[1]
      ), p(u, "r", o = /*handleRadius*/
      t[5] + 2 / /*scale*/
      t[2]), p(e, "class", "a9s-touch-handle");
    },
    m(d, y) {
      V(d, e, y), nt(e, n), nt(e, i), nt(e, u), a || (c = [
        J(
          i,
          "dblclick",
          /*dblclick_handler*/
          t[9]
        ),
        J(
          i,
          "pointerdown",
          /*pointerdown_handler*/
          t[10]
        ),
        J(
          i,
          "pointerdown",
          /*onPointerDown*/
          t[6]
        ),
        J(
          i,
          "pointerup",
          /*pointerup_handler*/
          t[11]
        ),
        J(
          i,
          "pointerup",
          /*onPointerUp*/
          t[7]
        )
      ], a = !0);
    },
    p(d, y) {
      y & /*x*/
      1 && p(
        n,
        "cx",
        /*x*/
        d[0]
      ), y & /*y*/
      2 && p(
        n,
        "cy",
        /*y*/
        d[1]
      ), y & /*handleRadius*/
      32 && r !== (r = /*handleRadius*/
      d[5] * 10) && p(n, "r", r), y & /*touched*/
      16 && nn(
        n,
        "touched",
        /*touched*/
        d[4]
      ), y & /*x*/
      1 && p(
        i,
        "cx",
        /*x*/
        d[0]
      ), y & /*y*/
      2 && p(
        i,
        "cy",
        /*y*/
        d[1]
      ), y & /*handleRadius, scale*/
      36 && s !== (s = /*handleRadius*/
      d[5] + 10 / /*scale*/
      d[2]) && p(i, "r", s), y & /*x*/
      1 && p(
        u,
        "cx",
        /*x*/
        d[0]
      ), y & /*y*/
      2 && p(
        u,
        "cy",
        /*y*/
        d[1]
      ), y & /*handleRadius, scale*/
      36 && o !== (o = /*handleRadius*/
      d[5] + 2 / /*scale*/
      d[2]) && p(u, "r", o);
    },
    d(d) {
      d && H(e), a = !1, St(c);
    }
  };
}
function cn(t) {
  let e, n;
  return {
    c() {
      e = D("circle"), p(e, "class", "a9s-handle-selected"), p(
        e,
        "cx",
        /*x*/
        t[0]
      ), p(
        e,
        "cy",
        /*y*/
        t[1]
      ), p(e, "r", n = /*handleRadius*/
      t[5] + 8 / /*scale*/
      t[2]);
    },
    m(r, i) {
      V(r, e, i);
    },
    p(r, i) {
      i & /*x*/
      1 && p(
        e,
        "cx",
        /*x*/
        r[0]
      ), i & /*y*/
      2 && p(
        e,
        "cy",
        /*y*/
        r[1]
      ), i & /*handleRadius, scale*/
      36 && n !== (n = /*handleRadius*/
      r[5] + 8 / /*scale*/
      r[2]) && p(e, "r", n);
    },
    d(r) {
      r && H(e);
    }
  };
}
function Zr(t) {
  let e;
  function n(i, s) {
    return Rt ? Kr : Vr;
  }
  let r = n()(t);
  return {
    c() {
      r.c(), e = re();
    },
    m(i, s) {
      r.m(i, s), V(i, e, s);
    },
    p(i, [s]) {
      r.p(i, s);
    },
    i: Bt,
    o: Bt,
    d(i) {
      i && H(e), r.d(i);
    }
  };
}
function Wr(t, e, n) {
  let r, { x: i } = e, { y: s } = e, { scale: u } = e, { selected: o = void 0 } = e, a = !1;
  const c = (A) => {
    A.pointerType === "touch" && n(4, a = !0);
  }, d = () => n(4, a = !1);
  function y(A) {
    ct.call(this, t, A);
  }
  function b(A) {
    ct.call(this, t, A);
  }
  function L(A) {
    ct.call(this, t, A);
  }
  function N(A) {
    ct.call(this, t, A);
  }
  function M(A) {
    ct.call(this, t, A);
  }
  function k(A) {
    ct.call(this, t, A);
  }
  function K(A) {
    ct.call(this, t, A);
  }
  function O(A) {
    ct.call(this, t, A);
  }
  return t.$$set = (A) => {
    n(8, e = Be(Be({}, e), tn(A))), "x" in A && n(0, i = A.x), "y" in A && n(1, s = A.y), "scale" in A && n(2, u = A.scale), "selected" in A && n(3, o = A.selected);
  }, t.$$.update = () => {
    t.$$.dirty & /*scale*/
    4 && n(5, r = 4 / u);
  }, e = tn(e), [
    i,
    s,
    u,
    o,
    a,
    r,
    c,
    d,
    e,
    y,
    b,
    L,
    N,
    M,
    k,
    K,
    O
  ];
}
class Ut extends Kt {
  constructor(e) {
    super(), Vt(this, e, Wr, Zr, Xt, { x: 0, y: 1, scale: 2, selected: 3 });
  }
}
function Qr(t) {
  let e, n, r, i, s, u, o;
  return {
    c() {
      e = D("g"), n = D("circle"), i = D("circle"), s = D("circle"), p(n, "class", "a9s-polygon-midpoint-buffer svelte-12ykj76"), p(
        n,
        "cx",
        /*x*/
        t[0]
      ), p(
        n,
        "cy",
        /*y*/
        t[1]
      ), p(n, "r", r = 1.75 * /*handleRadius*/
      t[2]), p(i, "class", "a9s-polygon-midpoint-outer svelte-12ykj76"), p(
        i,
        "cx",
        /*x*/
        t[0]
      ), p(
        i,
        "cy",
        /*y*/
        t[1]
      ), p(
        i,
        "r",
        /*handleRadius*/
        t[2]
      ), p(s, "class", "a9s-polygon-midpoint-inner svelte-12ykj76"), p(
        s,
        "cx",
        /*x*/
        t[0]
      ), p(
        s,
        "cy",
        /*y*/
        t[1]
      ), p(
        s,
        "r",
        /*handleRadius*/
        t[2]
      ), p(e, "class", "a9s-polygon-midpoint svelte-12ykj76");
    },
    m(a, c) {
      V(a, e, c), nt(e, n), nt(e, i), nt(e, s), u || (o = [
        J(
          n,
          "pointerdown",
          /*pointerdown_handler*/
          t[5]
        ),
        J(
          n,
          "pointerdown",
          /*onPointerDown*/
          t[3]
        )
      ], u = !0);
    },
    p(a, c) {
      c & /*x*/
      1 && p(
        n,
        "cx",
        /*x*/
        a[0]
      ), c & /*y*/
      2 && p(
        n,
        "cy",
        /*y*/
        a[1]
      ), c & /*handleRadius*/
      4 && r !== (r = 1.75 * /*handleRadius*/
      a[2]) && p(n, "r", r), c & /*x*/
      1 && p(
        i,
        "cx",
        /*x*/
        a[0]
      ), c & /*y*/
      2 && p(
        i,
        "cy",
        /*y*/
        a[1]
      ), c & /*handleRadius*/
      4 && p(
        i,
        "r",
        /*handleRadius*/
        a[2]
      ), c & /*x*/
      1 && p(
        s,
        "cx",
        /*x*/
        a[0]
      ), c & /*y*/
      2 && p(
        s,
        "cy",
        /*y*/
        a[1]
      ), c & /*handleRadius*/
      4 && p(
        s,
        "r",
        /*handleRadius*/
        a[2]
      );
    },
    d(a) {
      a && H(e), u = !1, St(o);
    }
  };
}
function zr(t) {
  let e;
  return {
    c() {
      e = D("circle"), p(
        e,
        "cx",
        /*x*/
        t[0]
      ), p(
        e,
        "cy",
        /*y*/
        t[1]
      ), p(
        e,
        "r",
        /*handleRadius*/
        t[2]
      );
    },
    m(n, r) {
      V(n, e, r);
    },
    p(n, r) {
      r & /*x*/
      1 && p(
        e,
        "cx",
        /*x*/
        n[0]
      ), r & /*y*/
      2 && p(
        e,
        "cy",
        /*y*/
        n[1]
      ), r & /*handleRadius*/
      4 && p(
        e,
        "r",
        /*handleRadius*/
        n[2]
      );
    },
    d(n) {
      n && H(e);
    }
  };
}
function Jr(t) {
  let e;
  function n(i, s) {
    return Rt ? zr : Qr;
  }
  let r = n()(t);
  return {
    c() {
      r.c(), e = re();
    },
    m(i, s) {
      r.m(i, s), V(i, e, s);
    },
    p(i, [s]) {
      r.p(i, s);
    },
    i: Bt,
    o: Bt,
    d(i) {
      i && H(e), r.d(i);
    }
  };
}
function Yr(t, e, n) {
  let r, { x: i } = e, { y: s } = e, { scale: u } = e;
  const o = (c) => {
    c.pointerType;
  };
  function a(c) {
    ct.call(this, t, c);
  }
  return t.$$set = (c) => {
    "x" in c && n(0, i = c.x), "y" in c && n(1, s = c.y), "scale" in c && n(4, u = c.scale);
  }, t.$$.update = () => {
    t.$$.dirty & /*scale*/
    16 && n(2, r = 4 / u);
  }, [i, s, r, o, u, a];
}
class Kn extends Kt {
  constructor(e) {
    super(), Vt(this, e, Yr, Jr, Xt, { x: 0, y: 1, scale: 4 });
  }
}
function Oe(t) {
  const e = t.slice(), n = (
    /*midpoints*/
    e[10][
      /*visibleMidpoint*/
      e[6]
    ]
  );
  return e[28] = n.point, e;
}
function un(t, e, n) {
  const r = t.slice();
  return r[28] = e[n], r[30] = n, r;
}
function Le(t) {
  const e = t.slice(), n = (
    /*midpoints*/
    e[10][
      /*visibleMidpoint*/
      e[6]
    ]
  );
  return e[28] = n.point, e;
}
function Me(t) {
  const e = t.slice(), n = (
    /*midpoints*/
    e[10][
      /*visibleMidpoint*/
      e[6]
    ]
  );
  return e[28] = n.point, e;
}
function an(t) {
  let e, n, r, i;
  return {
    c() {
      e = D("circle"), p(e, "cx", n = /*point*/
      t[28][0]), p(e, "cy", r = /*point*/
      t[28][1]), p(e, "r", i = ee / /*viewportScale*/
      t[3]), p(e, "class", "svelte-1h2slbm");
    },
    m(s, u) {
      V(s, e, u);
    },
    p(s, u) {
      u[0] & /*midpoints, visibleMidpoint*/
      1088 && n !== (n = /*point*/
      s[28][0]) && p(e, "cx", n), u[0] & /*midpoints, visibleMidpoint*/
      1088 && r !== (r = /*point*/
      s[28][1]) && p(e, "cy", r), u[0] & /*viewportScale*/
      8 && i !== (i = ee / /*viewportScale*/
      s[3]) && p(e, "r", i);
    },
    d(s) {
      s && H(e);
    }
  };
}
function fn(t) {
  let e, n, r, i, s, u, o, a, c, d;
  return {
    c() {
      e = D("mask"), n = D("rect"), o = D("circle"), p(n, "x", r = /*mask*/
      t[9].x), p(n, "y", i = /*mask*/
      t[9].y), p(n, "width", s = /*mask*/
      t[9].w), p(n, "height", u = /*mask*/
      t[9].h), p(n, "class", "svelte-1h2slbm"), p(o, "cx", a = /*point*/
      t[28][0]), p(o, "cy", c = /*point*/
      t[28][1]), p(o, "r", d = ee / /*viewportScale*/
      t[3]), p(o, "class", "svelte-1h2slbm"), p(e, "id", `${/*maskId*/
      t[19]}-inner`), p(e, "class", "a9s-polygon-editor-mask svelte-1h2slbm");
    },
    m(y, b) {
      V(y, e, b), nt(e, n), nt(e, o);
    },
    p(y, b) {
      b[0] & /*mask*/
      512 && r !== (r = /*mask*/
      y[9].x) && p(n, "x", r), b[0] & /*mask*/
      512 && i !== (i = /*mask*/
      y[9].y) && p(n, "y", i), b[0] & /*mask*/
      512 && s !== (s = /*mask*/
      y[9].w) && p(n, "width", s), b[0] & /*mask*/
      512 && u !== (u = /*mask*/
      y[9].h) && p(n, "height", u), b[0] & /*midpoints, visibleMidpoint*/
      1088 && a !== (a = /*point*/
      y[28][0]) && p(o, "cx", a), b[0] & /*midpoints, visibleMidpoint*/
      1088 && c !== (c = /*point*/
      y[28][1]) && p(o, "cy", c), b[0] & /*viewportScale*/
      8 && d !== (d = ee / /*viewportScale*/
      y[3]) && p(o, "r", d);
    },
    d(y) {
      y && H(e);
    }
  };
}
function hn(t) {
  let e, n;
  return e = new Ut({
    props: {
      class: "a9s-corner-handle",
      x: (
        /*point*/
        t[28][0]
      ),
      y: (
        /*point*/
        t[28][1]
      ),
      scale: (
        /*viewportScale*/
        t[3]
      ),
      selected: (
        /*selectedCorners*/
        t[8].includes(
          /*idx*/
          t[30]
        )
      )
    }
  }), e.$on(
    "pointerenter",
    /*onEnterHandle*/
    t[11]
  ), e.$on(
    "pointerleave",
    /*onLeaveHandle*/
    t[12]
  ), e.$on(
    "pointerdown",
    /*onHandlePointerDown*/
    t[15]
  ), e.$on("pointerdown", function() {
    ot(
      /*grab*/
      t[27](`HANDLE-${/*idx*/
      t[30]}`)
    ) && t[27](`HANDLE-${/*idx*/
    t[30]}`).apply(this, arguments);
  }), e.$on(
    "pointerup",
    /*onHandlePointerUp*/
    t[16](
      /*idx*/
      t[30]
    )
  ), {
    c() {
      bt(e.$$.fragment);
    },
    m(r, i) {
      vt(e, r, i), n = !0;
    },
    p(r, i) {
      t = r;
      const s = {};
      i[0] & /*geom*/
      32 && (s.x = /*point*/
      t[28][0]), i[0] & /*geom*/
      32 && (s.y = /*point*/
      t[28][1]), i[0] & /*viewportScale*/
      8 && (s.scale = /*viewportScale*/
      t[3]), i[0] & /*selectedCorners*/
      256 && (s.selected = /*selectedCorners*/
      t[8].includes(
        /*idx*/
        t[30]
      )), e.$set(s);
    },
    i(r) {
      n || (Y(e.$$.fragment, r), n = !0);
    },
    o(r) {
      rt(e.$$.fragment, r), n = !1;
    },
    d(r) {
      Et(e, r);
    }
  };
}
function pn(t) {
  let e, n;
  return e = new Kn({
    props: {
      x: (
        /*point*/
        t[28][0]
      ),
      y: (
        /*point*/
        t[28][1]
      ),
      scale: (
        /*viewportScale*/
        t[3]
      )
    }
  }), e.$on("pointerdown", function() {
    ot(
      /*onAddPoint*/
      t[18](
        /*visibleMidpoint*/
        t[6]
      )
    ) && t[18](
      /*visibleMidpoint*/
      t[6]
    ).apply(this, arguments);
  }), {
    c() {
      bt(e.$$.fragment);
    },
    m(r, i) {
      vt(e, r, i), n = !0;
    },
    p(r, i) {
      t = r;
      const s = {};
      i[0] & /*midpoints, visibleMidpoint*/
      1088 && (s.x = /*point*/
      t[28][0]), i[0] & /*midpoints, visibleMidpoint*/
      1088 && (s.y = /*point*/
      t[28][1]), i[0] & /*viewportScale*/
      8 && (s.scale = /*viewportScale*/
      t[3]), e.$set(s);
    },
    i(r) {
      n || (Y(e.$$.fragment, r), n = !0);
    },
    o(r) {
      rt(e.$$.fragment, r), n = !1;
    },
    d(r) {
      Et(e, r);
    }
  };
}
function ti(t) {
  let e, n, r, i, s, u, o, a, c, d, y, b, L, N, M, k, K, O, A, X, q, U = (
    /*visibleMidpoint*/
    t[6] !== void 0 && !/*isHandleHovered*/
    t[7] && an(Me(t))
  ), C = (
    /*visibleMidpoint*/
    t[6] !== void 0 && !/*isHandleHovered*/
    t[7] && fn(Le(t))
  ), l = _t(
    /*geom*/
    t[5].points
  ), f = [];
  for (let x = 0; x < l.length; x += 1)
    f[x] = hn(un(t, l, x));
  const g = (x) => rt(f[x], 1, 1, () => {
    f[x] = null;
  });
  let $ = (
    /*visibleMidpoint*/
    t[6] !== void 0 && !/*isHandleHovered*/
    t[7] && pn(Oe(t))
  );
  return {
    c() {
      e = D("defs"), n = D("mask"), r = D("rect"), a = D("polygon"), U && U.c(), C && C.c(), d = pt(), y = D("polygon"), L = pt(), N = D("polygon"), k = pt();
      for (let x = 0; x < f.length; x += 1)
        f[x].c();
      K = pt(), $ && $.c(), O = re(), p(r, "x", i = /*mask*/
      t[9].x), p(r, "y", s = /*mask*/
      t[9].y), p(r, "width", u = /*mask*/
      t[9].w), p(r, "height", o = /*mask*/
      t[9].h), p(r, "class", "svelte-1h2slbm"), p(a, "points", c = /*geom*/
      t[5].points.map(gn).join(" ")), p(a, "class", "svelte-1h2slbm"), p(n, "id", `${/*maskId*/
      t[19]}-outer`), p(n, "class", "a9s-polygon-editor-mask svelte-1h2slbm"), p(y, "class", "a9s-outer"), p(y, "mask", `url(#${/*maskId*/
      t[19]}-outer)`), p(y, "points", b = /*geom*/
      t[5].points.map(dn).join(" ")), p(N, "class", "a9s-inner a9s-shape-handle"), p(N, "mask", `url(#${/*maskId*/
      t[19]}-inner)`), p(
        N,
        "style",
        /*computedStyle*/
        t[1]
      ), p(N, "points", M = /*geom*/
      t[5].points.map(yn).join(" "));
    },
    m(x, v) {
      V(x, e, v), nt(e, n), nt(n, r), nt(n, a), U && U.m(n, null), C && C.m(e, null), V(x, d, v), V(x, y, v), V(x, L, v), V(x, N, v), V(x, k, v);
      for (let w = 0; w < f.length; w += 1)
        f[w] && f[w].m(x, v);
      V(x, K, v), $ && $.m(x, v), V(x, O, v), A = !0, X || (q = [
        J(
          y,
          "pointerup",
          /*onShapePointerUp*/
          t[14]
        ),
        J(y, "pointerdown", function() {
          ot(
            /*grab*/
            t[27]("SHAPE")
          ) && t[27]("SHAPE").apply(this, arguments);
        }),
        J(
          N,
          "pointermove",
          /*onPointerMove*/
          t[13]
        ),
        J(
          N,
          "pointerup",
          /*onShapePointerUp*/
          t[14]
        ),
        J(N, "pointerdown", function() {
          ot(
            /*grab*/
            t[27]("SHAPE")
          ) && t[27]("SHAPE").apply(this, arguments);
        })
      ], X = !0);
    },
    p(x, v) {
      if (t = x, (!A || v[0] & /*mask*/
      512 && i !== (i = /*mask*/
      t[9].x)) && p(r, "x", i), (!A || v[0] & /*mask*/
      512 && s !== (s = /*mask*/
      t[9].y)) && p(r, "y", s), (!A || v[0] & /*mask*/
      512 && u !== (u = /*mask*/
      t[9].w)) && p(r, "width", u), (!A || v[0] & /*mask*/
      512 && o !== (o = /*mask*/
      t[9].h)) && p(r, "height", o), (!A || v[0] & /*geom*/
      32 && c !== (c = /*geom*/
      t[5].points.map(gn).join(" "))) && p(a, "points", c), /*visibleMidpoint*/
      t[6] !== void 0 && !/*isHandleHovered*/
      t[7] ? U ? U.p(Me(t), v) : (U = an(Me(t)), U.c(), U.m(n, null)) : U && (U.d(1), U = null), /*visibleMidpoint*/
      t[6] !== void 0 && !/*isHandleHovered*/
      t[7] ? C ? C.p(Le(t), v) : (C = fn(Le(t)), C.c(), C.m(e, null)) : C && (C.d(1), C = null), (!A || v[0] & /*geom*/
      32 && b !== (b = /*geom*/
      t[5].points.map(dn).join(" "))) && p(y, "points", b), (!A || v[0] & /*computedStyle*/
      2) && p(
        N,
        "style",
        /*computedStyle*/
        t[1]
      ), (!A || v[0] & /*geom*/
      32 && M !== (M = /*geom*/
      t[5].points.map(yn).join(" "))) && p(N, "points", M), v[0] & /*geom, viewportScale, selectedCorners, onEnterHandle, onLeaveHandle, onHandlePointerDown, grab, onHandlePointerUp*/
      134322472) {
        l = _t(
          /*geom*/
          t[5].points
        );
        let w;
        for (w = 0; w < l.length; w += 1) {
          const h = un(t, l, w);
          f[w] ? (f[w].p(h, v), Y(f[w], 1)) : (f[w] = hn(h), f[w].c(), Y(f[w], 1), f[w].m(K.parentNode, K));
        }
        for (jt(), w = l.length; w < f.length; w += 1)
          g(w);
        Ht();
      }
      t[6] !== void 0 && !/*isHandleHovered*/
      t[7] ? $ ? ($.p(Oe(t), v), v[0] & /*visibleMidpoint, isHandleHovered*/
      192 && Y($, 1)) : ($ = pn(Oe(t)), $.c(), Y($, 1), $.m(O.parentNode, O)) : $ && (jt(), rt($, 1, 1, () => {
        $ = null;
      }), Ht());
    },
    i(x) {
      if (!A) {
        for (let v = 0; v < l.length; v += 1)
          Y(f[v]);
        Y($), A = !0;
      }
    },
    o(x) {
      f = f.filter(Boolean);
      for (let v = 0; v < f.length; v += 1)
        rt(f[v]);
      rt($), A = !1;
    },
    d(x) {
      x && (H(e), H(d), H(y), H(L), H(N), H(k), H(K), H(O)), U && U.d(), C && C.d(), we(f, x), $ && $.d(x), X = !1, St(q);
    }
  };
}
function ei(t) {
  let e, n;
  return e = new He({
    props: {
      shape: (
        /*shape*/
        t[0]
      ),
      transform: (
        /*transform*/
        t[2]
      ),
      editor: (
        /*editor*/
        t[17]
      ),
      svgEl: (
        /*svgEl*/
        t[4]
      ),
      $$slots: {
        default: [
          ti,
          ({ grab: r }) => ({ 27: r }),
          ({ grab: r }) => [r ? 134217728 : 0]
        ]
      },
      $$scope: { ctx: t }
    }
  }), e.$on(
    "change",
    /*change_handler*/
    t[20]
  ), e.$on(
    "grab",
    /*grab_handler*/
    t[21]
  ), e.$on(
    "release",
    /*release_handler*/
    t[22]
  ), {
    c() {
      bt(e.$$.fragment);
    },
    m(r, i) {
      vt(e, r, i), n = !0;
    },
    p(r, i) {
      const s = {};
      i[0] & /*shape*/
      1 && (s.shape = /*shape*/
      r[0]), i[0] & /*transform*/
      4 && (s.transform = /*transform*/
      r[2]), i[0] & /*svgEl*/
      16 && (s.svgEl = /*svgEl*/
      r[4]), i[0] & /*midpoints, visibleMidpoint, viewportScale, isHandleHovered, geom, selectedCorners, grab, computedStyle, mask*/
      134219754 | i[1] & /*$$scope*/
      1 && (s.$$scope = { dirty: i, ctx: r }), e.$set(s);
    },
    i(r) {
      n || (Y(e.$$.fragment, r), n = !0);
    },
    o(r) {
      rt(e.$$.fragment, r), n = !1;
    },
    d(r) {
      Et(e, r);
    }
  };
}
const ni = 250, ri = 1e3, ii = 12, ee = 4.5, gn = (t) => t.join(","), dn = (t) => t.join(","), yn = (t) => t.join(",");
function si(t, e, n) {
  let r, i, s;
  const u = Fe();
  let { shape: o } = e, { computedStyle: a } = e, { transform: c } = e, { viewportScale: d = 1 } = e, { svgEl: y } = e, b, L = !1, N, M = [];
  const k = () => n(7, L = !0), K = () => n(7, L = !1), O = (w) => {
    if (M.length > 0 || !i.some((I) => I.visible)) {
      n(6, b = void 0);
      return;
    }
    const [h, m] = c.elementToImage(w.offsetX, w.offsetY), E = (I) => Math.pow(I[0] - h, 2) + Math.pow(I[1] - m, 2), S = r.points.reduce((I, R) => E(R) < E(I) ? R : I), T = i.filter((I) => I.visible).reduce((I, R) => E(R.point) < E(I.point) ? R : I), P = Math.pow(ri / d, 2);
    E(S) < P || E(T.point) < P ? n(6, b = i.indexOf(T)) : n(6, b = void 0);
  }, A = () => {
    document.activeElement !== y && y.focus();
  }, X = () => {
    n(8, M = []), A();
  }, q = (w) => {
    n(7, L = !0), w.preventDefault(), w.stopPropagation(), N = performance.now();
  }, U = (w) => (h) => {
    if (!N || Rt || performance.now() - N > ni) return;
    const m = M.includes(w);
    h.metaKey || h.ctrlKey || h.shiftKey ? m ? n(8, M = M.filter((E) => E !== w)) : n(8, M = [...M, w]) : m && M.length > 1 ? n(8, M = [w]) : m ? n(8, M = []) : n(8, M = [w]), A();
  }, C = (w, h, m) => {
    A();
    let E;
    const S = w.geometry;
    M.length > 1 ? E = S.points.map(([P, I], R) => M.includes(R) ? [P + m[0], I + m[1]] : [P, I]) : h === "SHAPE" ? E = S.points.map(([P, I]) => [P + m[0], I + m[1]]) : E = S.points.map(([P, I], R) => h === `HANDLE-${R}` ? [P + m[0], I + m[1]] : [P, I]);
    const T = Lt(E);
    return { ...w, geometry: { points: E, bounds: T } };
  }, l = (w) => async (h) => {
    h.stopPropagation();
    const m = [
      ...r.points.slice(0, w + 1),
      i[w].point,
      ...r.points.slice(w + 1)
    ], E = Lt(m);
    u("change", { ...o, geometry: { points: m, bounds: E } }), await Hn();
    const S = [...document.querySelectorAll(".a9s-handle")][w + 1];
    if (S != null && S.firstChild) {
      const T = new PointerEvent(
        "pointerdown",
        {
          bubbles: !0,
          cancelable: !0,
          clientX: h.clientX,
          clientY: h.clientY,
          pointerId: h.pointerId,
          pointerType: h.pointerType,
          isPrimary: h.isPrimary,
          buttons: h.buttons
        }
      );
      S.firstChild.dispatchEvent(T);
    }
  }, f = () => {
    if (r.points.length - M.length < 3) return;
    const w = r.points.filter((m, E) => !M.includes(E)), h = Lt(w);
    u("change", { ...o, geometry: { points: w, bounds: h } }), n(8, M = []);
  };
  Un(() => {
    if (Rt) return;
    const w = (h) => {
      (h.key === "Delete" || h.key === "Backspace") && (h.preventDefault(), f());
    };
    return y.addEventListener("pointermove", O), y.addEventListener("keydown", w), () => {
      y.removeEventListener("pointermove", O), y.removeEventListener("keydown", w);
    };
  });
  const g = `polygon-mask-${Math.random().toString(36).substring(2, 12)}`;
  function $(w) {
    ct.call(this, t, w);
  }
  function x(w) {
    ct.call(this, t, w);
  }
  function v(w) {
    ct.call(this, t, w);
  }
  return t.$$set = (w) => {
    "shape" in w && n(0, o = w.shape), "computedStyle" in w && n(1, a = w.computedStyle), "transform" in w && n(2, c = w.transform), "viewportScale" in w && n(3, d = w.viewportScale), "svgEl" in w && n(4, y = w.svgEl);
  }, t.$$.update = () => {
    t.$$.dirty[0] & /*shape*/
    1 && n(5, r = o.geometry), t.$$.dirty[0] & /*geom, viewportScale*/
    40 && n(10, i = Rt ? [] : r.points.map((w, h) => {
      const m = h === r.points.length - 1 ? r.points[0] : r.points[h + 1], E = (w[0] + m[0]) / 2, S = (w[1] + m[1]) / 2, T = Math.sqrt(Math.pow(m[0] - E, 2) + Math.pow(m[1] - S, 2)) > ii / d;
      return { point: [E, S], visible: T };
    })), t.$$.dirty[0] & /*geom, viewportScale*/
    40 && n(9, s = je(r.bounds, ee / d));
  }, [
    o,
    a,
    c,
    d,
    y,
    r,
    b,
    L,
    M,
    s,
    i,
    k,
    K,
    O,
    X,
    q,
    U,
    C,
    l,
    g,
    $,
    x,
    v
  ];
}
class oi extends Kt {
  constructor(e) {
    super(), Vt(
      this,
      e,
      si,
      ei,
      Xt,
      {
        shape: 0,
        computedStyle: 1,
        transform: 2,
        viewportScale: 3,
        svgEl: 4
      },
      null,
      [-1, -1]
    );
  }
}
function li(t) {
  let e, n, r, i, s, u, o, a, c, d, y, b, L, N, M, k, K, O, A, X, q, U, C, l, f, g, $, x, v, w, h, m, E, S, T, P, I, R, F, Q, G, W, it, yt, Mt, at, ft, gt, ht, et, tt, Nt, z, ve, Xe;
  return at = new Ut({
    props: {
      class: "a9s-corner-handle-topleft",
      x: (
        /*geom*/
        t[5].x
      ),
      y: (
        /*geom*/
        t[5].y
      ),
      scale: (
        /*viewportScale*/
        t[3]
      )
    }
  }), at.$on("pointerdown", function() {
    ot(
      /*grab*/
      t[12]("TOP_LEFT")
    ) && t[12]("TOP_LEFT").apply(this, arguments);
  }), gt = new Ut({
    props: {
      class: "a9s-corner-handle-topright",
      x: (
        /*geom*/
        t[5].x + /*geom*/
        t[5].w
      ),
      y: (
        /*geom*/
        t[5].y
      ),
      scale: (
        /*viewportScale*/
        t[3]
      )
    }
  }), gt.$on("pointerdown", function() {
    ot(
      /*grab*/
      t[12]("TOP_RIGHT")
    ) && t[12]("TOP_RIGHT").apply(this, arguments);
  }), et = new Ut({
    props: {
      class: "a9s-corner-handle-bottomright",
      x: (
        /*geom*/
        t[5].x + /*geom*/
        t[5].w
      ),
      y: (
        /*geom*/
        t[5].y + /*geom*/
        t[5].h
      ),
      scale: (
        /*viewportScale*/
        t[3]
      )
    }
  }), et.$on("pointerdown", function() {
    ot(
      /*grab*/
      t[12]("BOTTOM_RIGHT")
    ) && t[12]("BOTTOM_RIGHT").apply(this, arguments);
  }), Nt = new Ut({
    props: {
      class: "a9s-corner-handle-bottomleft",
      x: (
        /*geom*/
        t[5].x
      ),
      y: (
        /*geom*/
        t[5].y + /*geom*/
        t[5].h
      ),
      scale: (
        /*viewportScale*/
        t[3]
      )
    }
  }), Nt.$on("pointerdown", function() {
    ot(
      /*grab*/
      t[12]("BOTTOM_LEFT")
    ) && t[12]("BOTTOM_LEFT").apply(this, arguments);
  }), {
    c() {
      e = D("defs"), n = D("mask"), r = D("rect"), a = D("rect"), L = pt(), N = D("rect"), A = pt(), X = D("rect"), f = pt(), g = D("rect"), w = pt(), h = D("rect"), T = pt(), P = D("rect"), Q = pt(), G = D("rect"), Mt = pt(), bt(at.$$.fragment), ft = pt(), bt(gt.$$.fragment), ht = pt(), bt(et.$$.fragment), tt = pt(), bt(Nt.$$.fragment), p(r, "class", "rect-mask-bg svelte-1njczvj"), p(r, "x", i = /*mask*/
      t[6].x), p(r, "y", s = /*mask*/
      t[6].y), p(r, "width", u = /*mask*/
      t[6].w), p(r, "height", o = /*mask*/
      t[6].h), p(a, "class", "rect-mask-fg svelte-1njczvj"), p(a, "x", c = /*geom*/
      t[5].x), p(a, "y", d = /*geom*/
      t[5].y), p(a, "width", y = /*geom*/
      t[5].w), p(a, "height", b = /*geom*/
      t[5].h), p(
        n,
        "id",
        /*maskId*/
        t[8]
      ), p(n, "class", "a9s-rectangle-editor-mask svelte-1njczvj"), p(N, "class", "a9s-outer"), p(N, "mask", `url(#${/*maskId*/
      t[8]})`), p(N, "x", M = /*geom*/
      t[5].x), p(N, "y", k = /*geom*/
      t[5].y), p(N, "width", K = /*geom*/
      t[5].w), p(N, "height", O = /*geom*/
      t[5].h), p(X, "class", "a9s-inner a9s-shape-handle"), p(
        X,
        "style",
        /*computedStyle*/
        t[1]
      ), p(X, "x", q = /*geom*/
      t[5].x), p(X, "y", U = /*geom*/
      t[5].y), p(X, "width", C = /*geom*/
      t[5].w), p(X, "height", l = /*geom*/
      t[5].h), p(g, "class", "a9s-edge-handle a9s-edge-handle-top"), p(g, "x", $ = /*geom*/
      t[5].x), p(g, "y", x = /*geom*/
      t[5].y), p(g, "height", 1), p(g, "width", v = /*geom*/
      t[5].w), p(h, "class", "a9s-edge-handle a9s-edge-handle-right"), p(h, "x", m = /*geom*/
      t[5].x + /*geom*/
      t[5].w), p(h, "y", E = /*geom*/
      t[5].y), p(h, "height", S = /*geom*/
      t[5].h), p(h, "width", 1), p(P, "class", "a9s-edge-handle a9s-edge-handle-bottom"), p(P, "x", I = /*geom*/
      t[5].x), p(P, "y", R = /*geom*/
      t[5].y + /*geom*/
      t[5].h), p(P, "height", 1), p(P, "width", F = /*geom*/
      t[5].w), p(G, "class", "a9s-edge-handle a9s-edge-handle-left"), p(G, "x", W = /*geom*/
      t[5].x), p(G, "y", it = /*geom*/
      t[5].y), p(G, "height", yt = /*geom*/
      t[5].h), p(G, "width", 1);
    },
    m(Z, _) {
      V(Z, e, _), nt(e, n), nt(n, r), nt(n, a), V(Z, L, _), V(Z, N, _), V(Z, A, _), V(Z, X, _), V(Z, f, _), V(Z, g, _), V(Z, w, _), V(Z, h, _), V(Z, T, _), V(Z, P, _), V(Z, Q, _), V(Z, G, _), V(Z, Mt, _), vt(at, Z, _), V(Z, ft, _), vt(gt, Z, _), V(Z, ht, _), vt(et, Z, _), V(Z, tt, _), vt(Nt, Z, _), z = !0, ve || (Xe = [
        J(N, "pointerdown", function() {
          ot(
            /*grab*/
            t[12]("SHAPE")
          ) && t[12]("SHAPE").apply(this, arguments);
        }),
        J(X, "pointerdown", function() {
          ot(
            /*grab*/
            t[12]("SHAPE")
          ) && t[12]("SHAPE").apply(this, arguments);
        }),
        J(g, "pointerdown", function() {
          ot(
            /*grab*/
            t[12]("TOP")
          ) && t[12]("TOP").apply(this, arguments);
        }),
        J(h, "pointerdown", function() {
          ot(
            /*grab*/
            t[12]("RIGHT")
          ) && t[12]("RIGHT").apply(this, arguments);
        }),
        J(P, "pointerdown", function() {
          ot(
            /*grab*/
            t[12]("BOTTOM")
          ) && t[12]("BOTTOM").apply(this, arguments);
        }),
        J(G, "pointerdown", function() {
          ot(
            /*grab*/
            t[12]("LEFT")
          ) && t[12]("LEFT").apply(this, arguments);
        })
      ], ve = !0);
    },
    p(Z, _) {
      t = Z, (!z || _ & /*mask*/
      64 && i !== (i = /*mask*/
      t[6].x)) && p(r, "x", i), (!z || _ & /*mask*/
      64 && s !== (s = /*mask*/
      t[6].y)) && p(r, "y", s), (!z || _ & /*mask*/
      64 && u !== (u = /*mask*/
      t[6].w)) && p(r, "width", u), (!z || _ & /*mask*/
      64 && o !== (o = /*mask*/
      t[6].h)) && p(r, "height", o), (!z || _ & /*geom*/
      32 && c !== (c = /*geom*/
      t[5].x)) && p(a, "x", c), (!z || _ & /*geom*/
      32 && d !== (d = /*geom*/
      t[5].y)) && p(a, "y", d), (!z || _ & /*geom*/
      32 && y !== (y = /*geom*/
      t[5].w)) && p(a, "width", y), (!z || _ & /*geom*/
      32 && b !== (b = /*geom*/
      t[5].h)) && p(a, "height", b), (!z || _ & /*geom*/
      32 && M !== (M = /*geom*/
      t[5].x)) && p(N, "x", M), (!z || _ & /*geom*/
      32 && k !== (k = /*geom*/
      t[5].y)) && p(N, "y", k), (!z || _ & /*geom*/
      32 && K !== (K = /*geom*/
      t[5].w)) && p(N, "width", K), (!z || _ & /*geom*/
      32 && O !== (O = /*geom*/
      t[5].h)) && p(N, "height", O), (!z || _ & /*computedStyle*/
      2) && p(
        X,
        "style",
        /*computedStyle*/
        t[1]
      ), (!z || _ & /*geom*/
      32 && q !== (q = /*geom*/
      t[5].x)) && p(X, "x", q), (!z || _ & /*geom*/
      32 && U !== (U = /*geom*/
      t[5].y)) && p(X, "y", U), (!z || _ & /*geom*/
      32 && C !== (C = /*geom*/
      t[5].w)) && p(X, "width", C), (!z || _ & /*geom*/
      32 && l !== (l = /*geom*/
      t[5].h)) && p(X, "height", l), (!z || _ & /*geom*/
      32 && $ !== ($ = /*geom*/
      t[5].x)) && p(g, "x", $), (!z || _ & /*geom*/
      32 && x !== (x = /*geom*/
      t[5].y)) && p(g, "y", x), (!z || _ & /*geom*/
      32 && v !== (v = /*geom*/
      t[5].w)) && p(g, "width", v), (!z || _ & /*geom*/
      32 && m !== (m = /*geom*/
      t[5].x + /*geom*/
      t[5].w)) && p(h, "x", m), (!z || _ & /*geom*/
      32 && E !== (E = /*geom*/
      t[5].y)) && p(h, "y", E), (!z || _ & /*geom*/
      32 && S !== (S = /*geom*/
      t[5].h)) && p(h, "height", S), (!z || _ & /*geom*/
      32 && I !== (I = /*geom*/
      t[5].x)) && p(P, "x", I), (!z || _ & /*geom*/
      32 && R !== (R = /*geom*/
      t[5].y + /*geom*/
      t[5].h)) && p(P, "y", R), (!z || _ & /*geom*/
      32 && F !== (F = /*geom*/
      t[5].w)) && p(P, "width", F), (!z || _ & /*geom*/
      32 && W !== (W = /*geom*/
      t[5].x)) && p(G, "x", W), (!z || _ & /*geom*/
      32 && it !== (it = /*geom*/
      t[5].y)) && p(G, "y", it), (!z || _ & /*geom*/
      32 && yt !== (yt = /*geom*/
      t[5].h)) && p(G, "height", yt);
      const ie = {};
      _ & /*geom*/
      32 && (ie.x = /*geom*/
      t[5].x), _ & /*geom*/
      32 && (ie.y = /*geom*/
      t[5].y), _ & /*viewportScale*/
      8 && (ie.scale = /*viewportScale*/
      t[3]), at.$set(ie);
      const se = {};
      _ & /*geom*/
      32 && (se.x = /*geom*/
      t[5].x + /*geom*/
      t[5].w), _ & /*geom*/
      32 && (se.y = /*geom*/
      t[5].y), _ & /*viewportScale*/
      8 && (se.scale = /*viewportScale*/
      t[3]), gt.$set(se);
      const oe = {};
      _ & /*geom*/
      32 && (oe.x = /*geom*/
      t[5].x + /*geom*/
      t[5].w), _ & /*geom*/
      32 && (oe.y = /*geom*/
      t[5].y + /*geom*/
      t[5].h), _ & /*viewportScale*/
      8 && (oe.scale = /*viewportScale*/
      t[3]), et.$set(oe);
      const le = {};
      _ & /*geom*/
      32 && (le.x = /*geom*/
      t[5].x), _ & /*geom*/
      32 && (le.y = /*geom*/
      t[5].y + /*geom*/
      t[5].h), _ & /*viewportScale*/
      8 && (le.scale = /*viewportScale*/
      t[3]), Nt.$set(le);
    },
    i(Z) {
      z || (Y(at.$$.fragment, Z), Y(gt.$$.fragment, Z), Y(et.$$.fragment, Z), Y(Nt.$$.fragment, Z), z = !0);
    },
    o(Z) {
      rt(at.$$.fragment, Z), rt(gt.$$.fragment, Z), rt(et.$$.fragment, Z), rt(Nt.$$.fragment, Z), z = !1;
    },
    d(Z) {
      Z && (H(e), H(L), H(N), H(A), H(X), H(f), H(g), H(w), H(h), H(T), H(P), H(Q), H(G), H(Mt), H(ft), H(ht), H(tt)), Et(at, Z), Et(gt, Z), Et(et, Z), Et(Nt, Z), ve = !1, St(Xe);
    }
  };
}
function ci(t) {
  let e, n;
  return e = new He({
    props: {
      shape: (
        /*shape*/
        t[0]
      ),
      transform: (
        /*transform*/
        t[2]
      ),
      editor: (
        /*editor*/
        t[7]
      ),
      svgEl: (
        /*svgEl*/
        t[4]
      ),
      $$slots: {
        default: [
          li,
          ({ grab: r }) => ({ 12: r }),
          ({ grab: r }) => r ? 4096 : 0
        ]
      },
      $$scope: { ctx: t }
    }
  }), e.$on(
    "grab",
    /*grab_handler*/
    t[9]
  ), e.$on(
    "change",
    /*change_handler*/
    t[10]
  ), e.$on(
    "release",
    /*release_handler*/
    t[11]
  ), {
    c() {
      bt(e.$$.fragment);
    },
    m(r, i) {
      vt(e, r, i), n = !0;
    },
    p(r, [i]) {
      const s = {};
      i & /*shape*/
      1 && (s.shape = /*shape*/
      r[0]), i & /*transform*/
      4 && (s.transform = /*transform*/
      r[2]), i & /*svgEl*/
      16 && (s.svgEl = /*svgEl*/
      r[4]), i & /*$$scope, geom, viewportScale, grab, computedStyle, mask*/
      12394 && (s.$$scope = { dirty: i, ctx: r }), e.$set(s);
    },
    i(r) {
      n || (Y(e.$$.fragment, r), n = !0);
    },
    o(r) {
      rt(e.$$.fragment, r), n = !1;
    },
    d(r) {
      Et(e, r);
    }
  };
}
function ui(t, e, n) {
  let r, i, { shape: s } = e, { computedStyle: u } = e, { transform: o } = e, { viewportScale: a = 1 } = e, { svgEl: c } = e;
  const d = (M, k, K) => {
    const O = M.geometry.bounds;
    let [A, X] = [O.minX, O.minY], [q, U] = [O.maxX, O.maxY];
    const [C, l] = K;
    if (k === "SHAPE")
      A += C, q += C, X += l, U += l;
    else {
      switch (k) {
        case "TOP":
        case "TOP_LEFT":
        case "TOP_RIGHT": {
          X += l;
          break;
        }
        case "BOTTOM":
        case "BOTTOM_LEFT":
        case "BOTTOM_RIGHT": {
          U += l;
          break;
        }
      }
      switch (k) {
        case "LEFT":
        case "TOP_LEFT":
        case "BOTTOM_LEFT": {
          A += C;
          break;
        }
        case "RIGHT":
        case "TOP_RIGHT":
        case "BOTTOM_RIGHT": {
          q += C;
          break;
        }
      }
    }
    const f = Math.min(A, q), g = Math.min(X, U), $ = Math.abs(q - A), x = Math.abs(U - X);
    return {
      ...M,
      geometry: {
        x: f,
        y: g,
        w: $,
        h: x,
        bounds: {
          minX: f,
          minY: g,
          maxX: f + $,
          maxY: g + x
        }
      }
    };
  }, y = `rect-mask-${Math.random().toString(36).substring(2, 12)}`;
  function b(M) {
    ct.call(this, t, M);
  }
  function L(M) {
    ct.call(this, t, M);
  }
  function N(M) {
    ct.call(this, t, M);
  }
  return t.$$set = (M) => {
    "shape" in M && n(0, s = M.shape), "computedStyle" in M && n(1, u = M.computedStyle), "transform" in M && n(2, o = M.transform), "viewportScale" in M && n(3, a = M.viewportScale), "svgEl" in M && n(4, c = M.svgEl);
  }, t.$$.update = () => {
    t.$$.dirty & /*shape*/
    1 && n(5, r = s.geometry), t.$$.dirty & /*geom, viewportScale*/
    40 && n(6, i = je(r.bounds, 2 / a));
  }, [
    s,
    u,
    o,
    a,
    c,
    r,
    i,
    d,
    y,
    b,
    L,
    N
  ];
}
class ai extends Kt {
  constructor(e) {
    super(), Vt(this, e, ui, ci, Xt, {
      shape: 0,
      computedStyle: 1,
      transform: 2,
      viewportScale: 3,
      svgEl: 4
    });
  }
}
var mn = Object.prototype.hasOwnProperty;
function Ue(t, e) {
  var n, r;
  if (t === e) return !0;
  if (t && e && (n = t.constructor) === e.constructor) {
    if (n === Date) return t.getTime() === e.getTime();
    if (n === RegExp) return t.toString() === e.toString();
    if (n === Array) {
      if ((r = t.length) === e.length)
        for (; r-- && Ue(t[r], e[r]); ) ;
      return r === -1;
    }
    if (!n || typeof t == "object") {
      r = 0;
      for (n in t)
        if (mn.call(t, n) && ++r && !mn.call(e, n) || !(n in e) || !Ue(t[n], e[n])) return !1;
      return Object.keys(e).length === r;
    }
  }
  return t !== t && e !== e;
}
const fi = 12, hi = (t, e) => t.polygons.reduce((n, r, i) => {
  const s = r.rings.reduce((u, o, a) => {
    const c = o.points.map((d, y) => {
      const b = y === o.points.length - 1 ? o.points[0] : o.points[y + 1], L = (d[0] + b[0]) / 2, N = (d[1] + b[1]) / 2, M = Math.sqrt(
        Math.pow(b[0] - L, 2) + Math.pow(b[1] - N, 2)
      ) > fi / e;
      return { point: [L, N], visible: M, elementIdx: i, ringIdx: a, pointIdx: y };
    });
    return [...u, ...c];
  }, []);
  return [...n, ...s];
}, []);
function Pe(t) {
  const e = t.slice(), n = (
    /*midpoints*/
    e[10][
      /*visibleMidpoint*/
      e[6]
    ]
  );
  return e[29] = n.point, e;
}
function xn(t, e, n) {
  const r = t.slice();
  return r[30] = e[n], r[32] = n, r;
}
function wn(t, e, n) {
  const r = t.slice();
  return r[33] = e[n], r[35] = n, r;
}
function vn(t, e, n) {
  const r = t.slice();
  return r[29] = e[n], r[37] = n, r;
}
function Ie(t) {
  const e = t.slice(), n = (
    /*midpoints*/
    e[10][
      /*visibleMidpoint*/
      e[6]
    ]
  );
  return e[29] = n.point, e;
}
function Ne(t) {
  const e = t.slice(), n = (
    /*midpoints*/
    e[10][
      /*visibleMidpoint*/
      e[6]
    ]
  );
  return e[29] = n.point, e;
}
function En(t) {
  let e, n, r, i;
  return {
    c() {
      e = D("circle"), p(e, "cx", n = /*point*/
      t[29][0]), p(e, "cy", r = /*point*/
      t[29][1]), p(e, "r", i = ne / /*viewportScale*/
      t[3]), p(e, "class", "svelte-1vxo6dc");
    },
    m(s, u) {
      V(s, e, u);
    },
    p(s, u) {
      u[0] & /*midpoints, visibleMidpoint*/
      1088 && n !== (n = /*point*/
      s[29][0]) && p(e, "cx", n), u[0] & /*midpoints, visibleMidpoint*/
      1088 && r !== (r = /*point*/
      s[29][1]) && p(e, "cy", r), u[0] & /*viewportScale*/
      8 && i !== (i = ne / /*viewportScale*/
      s[3]) && p(e, "r", i);
    },
    d(s) {
      s && H(e);
    }
  };
}
function Sn(t) {
  let e, n, r, i, s, u, o, a, c, d;
  return {
    c() {
      e = D("mask"), n = D("rect"), o = D("circle"), p(n, "x", r = /*mask*/
      t[9].x), p(n, "y", i = /*mask*/
      t[9].y), p(n, "width", s = /*mask*/
      t[9].w), p(n, "height", u = /*mask*/
      t[9].h), p(n, "class", "svelte-1vxo6dc"), p(o, "cx", a = /*point*/
      t[29][0]), p(o, "cy", c = /*point*/
      t[29][1]), p(o, "r", d = ne / /*viewportScale*/
      t[3]), p(o, "class", "svelte-1vxo6dc"), p(e, "id", `${/*maskId*/
      t[18]}-${/*elementIdx*/
      t[32]}-inner`), p(e, "class", "a9s-multipolygon-editor-mask svelte-1vxo6dc");
    },
    m(y, b) {
      V(y, e, b), nt(e, n), nt(e, o);
    },
    p(y, b) {
      b[0] & /*mask*/
      512 && r !== (r = /*mask*/
      y[9].x) && p(n, "x", r), b[0] & /*mask*/
      512 && i !== (i = /*mask*/
      y[9].y) && p(n, "y", i), b[0] & /*mask*/
      512 && s !== (s = /*mask*/
      y[9].w) && p(n, "width", s), b[0] & /*mask*/
      512 && u !== (u = /*mask*/
      y[9].h) && p(n, "height", u), b[0] & /*midpoints, visibleMidpoint*/
      1088 && a !== (a = /*point*/
      y[29][0]) && p(o, "cx", a), b[0] & /*midpoints, visibleMidpoint*/
      1088 && c !== (c = /*point*/
      y[29][1]) && p(o, "cy", c), b[0] & /*viewportScale*/
      8 && d !== (d = ne / /*viewportScale*/
      y[3]) && p(o, "r", d);
    },
    d(y) {
      y && H(e);
    }
  };
}
function $n(t) {
  let e, n;
  function r(...i) {
    return (
      /*func*/
      t[19](
        /*elementIdx*/
        t[32],
        /*ringIdx*/
        t[35],
        /*pointIdx*/
        t[37],
        ...i
      )
    );
  }
  return e = new Ut({
    props: {
      class: "a9s-corner-handle",
      x: (
        /*point*/
        t[29][0]
      ),
      y: (
        /*point*/
        t[29][1]
      ),
      scale: (
        /*viewportScale*/
        t[3]
      ),
      selected: (
        /*selectedCorners*/
        t[8].some(r)
      )
    }
  }), e.$on(
    "pointerenter",
    /*onEnterHandle*/
    t[11]
  ), e.$on(
    "pointerleave",
    /*onLeaveHandle*/
    t[12]
  ), e.$on(
    "pointerdown",
    /*onHandlePointerDown*/
    t[14]
  ), e.$on("pointerdown", function() {
    ot(
      /*grab*/
      t[28](`HANDLE-${/*elementIdx*/
      t[32]}-${/*ringIdx*/
      t[35]}-${/*pointIdx*/
      t[37]}`)
    ) && t[28](`HANDLE-${/*elementIdx*/
    t[32]}-${/*ringIdx*/
    t[35]}-${/*pointIdx*/
    t[37]}`).apply(this, arguments);
  }), e.$on(
    "pointerup",
    /*onHandlePointerUp*/
    t[15](
      /*elementIdx*/
      t[32],
      /*ringIdx*/
      t[35],
      /*pointIdx*/
      t[37]
    )
  ), {
    c() {
      bt(e.$$.fragment);
    },
    m(i, s) {
      vt(e, i, s), n = !0;
    },
    p(i, s) {
      t = i;
      const u = {};
      s[0] & /*geom*/
      32 && (u.x = /*point*/
      t[29][0]), s[0] & /*geom*/
      32 && (u.y = /*point*/
      t[29][1]), s[0] & /*viewportScale*/
      8 && (u.scale = /*viewportScale*/
      t[3]), s[0] & /*selectedCorners*/
      256 && (u.selected = /*selectedCorners*/
      t[8].some(r)), e.$set(u);
    },
    i(i) {
      n || (Y(e.$$.fragment, i), n = !0);
    },
    o(i) {
      rt(e.$$.fragment, i), n = !1;
    },
    d(i) {
      Et(e, i);
    }
  };
}
function bn(t) {
  let e, n, r = _t(
    /*ring*/
    t[33].points
  ), i = [];
  for (let u = 0; u < r.length; u += 1)
    i[u] = $n(vn(t, r, u));
  const s = (u) => rt(i[u], 1, 1, () => {
    i[u] = null;
  });
  return {
    c() {
      for (let u = 0; u < i.length; u += 1)
        i[u].c();
      e = re();
    },
    m(u, o) {
      for (let a = 0; a < i.length; a += 1)
        i[a] && i[a].m(u, o);
      V(u, e, o), n = !0;
    },
    p(u, o) {
      if (o[0] & /*geom, viewportScale, selectedCorners, onEnterHandle, onLeaveHandle, onHandlePointerDown, grab, onHandlePointerUp*/
      268491048) {
        r = _t(
          /*ring*/
          u[33].points
        );
        let a;
        for (a = 0; a < r.length; a += 1) {
          const c = vn(u, r, a);
          i[a] ? (i[a].p(c, o), Y(i[a], 1)) : (i[a] = $n(c), i[a].c(), Y(i[a], 1), i[a].m(e.parentNode, e));
        }
        for (jt(), a = r.length; a < i.length; a += 1)
          s(a);
        Ht();
      }
    },
    i(u) {
      if (!n) {
        for (let o = 0; o < r.length; o += 1)
          Y(i[o]);
        n = !0;
      }
    },
    o(u) {
      i = i.filter(Boolean);
      for (let o = 0; o < i.length; o += 1)
        rt(i[o]);
      n = !1;
    },
    d(u) {
      u && H(e), we(i, u);
    }
  };
}
function Tn(t) {
  let e, n, r, i, s, u, o, a, c, d, y, b, L, N, M, k, K, O = (
    /*visibleMidpoint*/
    t[6] !== void 0 && !/*isHandleHovered*/
    t[7] && En(Ne(t))
  ), A = (
    /*visibleMidpoint*/
    t[6] !== void 0 && !/*isHandleHovered*/
    t[7] && Sn(Ie(t))
  ), X = _t(
    /*element*/
    t[30].rings
  ), q = [];
  for (let C = 0; C < X.length; C += 1)
    q[C] = bn(wn(t, X, C));
  const U = (C) => rt(q[C], 1, 1, () => {
    q[C] = null;
  });
  return {
    c() {
      e = D("g"), n = D("defs"), r = D("mask"), i = D("rect"), c = D("path"), O && O.c(), A && A.c(), y = D("path"), L = D("path");
      for (let C = 0; C < q.length; C += 1)
        q[C].c();
      p(i, "x", s = /*mask*/
      t[9].x), p(i, "y", u = /*mask*/
      t[9].y), p(i, "width", o = /*mask*/
      t[9].w), p(i, "height", a = /*mask*/
      t[9].h), p(i, "class", "svelte-1vxo6dc"), p(c, "d", d = qt(
        /*element*/
        t[30]
      )), p(c, "class", "svelte-1vxo6dc"), p(r, "id", `${/*maskId*/
      t[18]}-${/*elementIdx*/
      t[32]}-outer`), p(r, "class", "a9s-multipolygon-editor-mask svelte-1vxo6dc"), p(y, "class", "a9s-outer"), p(y, "mask", `url(#${/*maskId*/
      t[18]}-${/*elementIdx*/
      t[32]}-outer)`), p(y, "fill-rule", "evenodd"), p(y, "d", b = qt(
        /*element*/
        t[30]
      )), p(L, "class", "a9s-inner"), p(L, "mask", `url(#${/*maskId*/
      t[18]}-${/*elementIdx*/
      t[32]}-inner)`), p(
        L,
        "style",
        /*computedStyle*/
        t[1]
      ), p(L, "fill-rule", "evenodd"), p(L, "d", N = qt(
        /*element*/
        t[30]
      ));
    },
    m(C, l) {
      V(C, e, l), nt(e, n), nt(n, r), nt(r, i), nt(r, c), O && O.m(r, null), A && A.m(n, null), nt(e, y), nt(e, L);
      for (let f = 0; f < q.length; f += 1)
        q[f] && q[f].m(e, null);
      M = !0, k || (K = [
        J(
          y,
          "pointerup",
          /*onShapePointerUp*/
          t[13]
        ),
        J(y, "pointerdown", function() {
          ot(
            /*grab*/
            t[28]("SHAPE")
          ) && t[28]("SHAPE").apply(this, arguments);
        }),
        J(
          L,
          "pointerup",
          /*onShapePointerUp*/
          t[13]
        ),
        J(L, "pointerdown", function() {
          ot(
            /*grab*/
            t[28]("SHAPE")
          ) && t[28]("SHAPE").apply(this, arguments);
        })
      ], k = !0);
    },
    p(C, l) {
      if (t = C, (!M || l[0] & /*mask*/
      512 && s !== (s = /*mask*/
      t[9].x)) && p(i, "x", s), (!M || l[0] & /*mask*/
      512 && u !== (u = /*mask*/
      t[9].y)) && p(i, "y", u), (!M || l[0] & /*mask*/
      512 && o !== (o = /*mask*/
      t[9].w)) && p(i, "width", o), (!M || l[0] & /*mask*/
      512 && a !== (a = /*mask*/
      t[9].h)) && p(i, "height", a), (!M || l[0] & /*geom*/
      32 && d !== (d = qt(
        /*element*/
        t[30]
      ))) && p(c, "d", d), /*visibleMidpoint*/
      t[6] !== void 0 && !/*isHandleHovered*/
      t[7] ? O ? O.p(Ne(t), l) : (O = En(Ne(t)), O.c(), O.m(r, null)) : O && (O.d(1), O = null), /*visibleMidpoint*/
      t[6] !== void 0 && !/*isHandleHovered*/
      t[7] ? A ? A.p(Ie(t), l) : (A = Sn(Ie(t)), A.c(), A.m(n, null)) : A && (A.d(1), A = null), (!M || l[0] & /*geom*/
      32 && b !== (b = qt(
        /*element*/
        t[30]
      ))) && p(y, "d", b), (!M || l[0] & /*computedStyle*/
      2) && p(
        L,
        "style",
        /*computedStyle*/
        t[1]
      ), (!M || l[0] & /*geom*/
      32 && N !== (N = qt(
        /*element*/
        t[30]
      ))) && p(L, "d", N), l[0] & /*geom, viewportScale, selectedCorners, onEnterHandle, onLeaveHandle, onHandlePointerDown, grab, onHandlePointerUp*/
      268491048) {
        X = _t(
          /*element*/
          t[30].rings
        );
        let f;
        for (f = 0; f < X.length; f += 1) {
          const g = wn(t, X, f);
          q[f] ? (q[f].p(g, l), Y(q[f], 1)) : (q[f] = bn(g), q[f].c(), Y(q[f], 1), q[f].m(e, null));
        }
        for (jt(), f = X.length; f < q.length; f += 1)
          U(f);
        Ht();
      }
    },
    i(C) {
      if (!M) {
        for (let l = 0; l < X.length; l += 1)
          Y(q[l]);
        M = !0;
      }
    },
    o(C) {
      q = q.filter(Boolean);
      for (let l = 0; l < q.length; l += 1)
        rt(q[l]);
      M = !1;
    },
    d(C) {
      C && H(e), O && O.d(), A && A.d(), we(q, C), k = !1, St(K);
    }
  };
}
function On(t) {
  let e, n;
  return e = new Kn({
    props: {
      x: (
        /*point*/
        t[29][0]
      ),
      y: (
        /*point*/
        t[29][1]
      ),
      scale: (
        /*viewportScale*/
        t[3]
      )
    }
  }), e.$on("pointerdown", function() {
    ot(
      /*onAddPoint*/
      t[17](
        /*visibleMidpoint*/
        t[6]
      )
    ) && t[17](
      /*visibleMidpoint*/
      t[6]
    ).apply(this, arguments);
  }), {
    c() {
      bt(e.$$.fragment);
    },
    m(r, i) {
      vt(e, r, i), n = !0;
    },
    p(r, i) {
      t = r;
      const s = {};
      i[0] & /*midpoints, visibleMidpoint*/
      1088 && (s.x = /*point*/
      t[29][0]), i[0] & /*midpoints, visibleMidpoint*/
      1088 && (s.y = /*point*/
      t[29][1]), i[0] & /*viewportScale*/
      8 && (s.scale = /*viewportScale*/
      t[3]), e.$set(s);
    },
    i(r) {
      n || (Y(e.$$.fragment, r), n = !0);
    },
    o(r) {
      rt(e.$$.fragment, r), n = !1;
    },
    d(r) {
      Et(e, r);
    }
  };
}
function pi(t) {
  let e, n, r, i = _t(
    /*geom*/
    t[5].polygons
  ), s = [];
  for (let a = 0; a < i.length; a += 1)
    s[a] = Tn(xn(t, i, a));
  const u = (a) => rt(s[a], 1, 1, () => {
    s[a] = null;
  });
  let o = (
    /*visibleMidpoint*/
    t[6] !== void 0 && !/*isHandleHovered*/
    t[7] && On(Pe(t))
  );
  return {
    c() {
      for (let a = 0; a < s.length; a += 1)
        s[a].c();
      e = pt(), o && o.c(), n = re();
    },
    m(a, c) {
      for (let d = 0; d < s.length; d += 1)
        s[d] && s[d].m(a, c);
      V(a, e, c), o && o.m(a, c), V(a, n, c), r = !0;
    },
    p(a, c) {
      if (c[0] & /*geom, viewportScale, selectedCorners, onEnterHandle, onLeaveHandle, onHandlePointerDown, grab, onHandlePointerUp, maskId, computedStyle, onShapePointerUp, midpoints, visibleMidpoint, mask, isHandleHovered*/
      268763114) {
        i = _t(
          /*geom*/
          a[5].polygons
        );
        let d;
        for (d = 0; d < i.length; d += 1) {
          const y = xn(a, i, d);
          s[d] ? (s[d].p(y, c), Y(s[d], 1)) : (s[d] = Tn(y), s[d].c(), Y(s[d], 1), s[d].m(e.parentNode, e));
        }
        for (jt(), d = i.length; d < s.length; d += 1)
          u(d);
        Ht();
      }
      a[6] !== void 0 && !/*isHandleHovered*/
      a[7] ? o ? (o.p(Pe(a), c), c[0] & /*visibleMidpoint, isHandleHovered*/
      192 && Y(o, 1)) : (o = On(Pe(a)), o.c(), Y(o, 1), o.m(n.parentNode, n)) : o && (jt(), rt(o, 1, 1, () => {
        o = null;
      }), Ht());
    },
    i(a) {
      if (!r) {
        for (let c = 0; c < i.length; c += 1)
          Y(s[c]);
        Y(o), r = !0;
      }
    },
    o(a) {
      s = s.filter(Boolean);
      for (let c = 0; c < s.length; c += 1)
        rt(s[c]);
      rt(o), r = !1;
    },
    d(a) {
      a && (H(e), H(n)), we(s, a), o && o.d(a);
    }
  };
}
function gi(t) {
  let e, n;
  return e = new He({
    props: {
      shape: (
        /*shape*/
        t[0]
      ),
      transform: (
        /*transform*/
        t[2]
      ),
      editor: (
        /*editor*/
        t[16]
      ),
      svgEl: (
        /*svgEl*/
        t[4]
      ),
      $$slots: {
        default: [
          pi,
          ({ grab: r }) => ({ 28: r }),
          ({ grab: r }) => [r ? 268435456 : 0]
        ]
      },
      $$scope: { ctx: t }
    }
  }), e.$on(
    "change",
    /*change_handler*/
    t[20]
  ), e.$on(
    "grab",
    /*grab_handler*/
    t[21]
  ), e.$on(
    "release",
    /*release_handler*/
    t[22]
  ), {
    c() {
      bt(e.$$.fragment);
    },
    m(r, i) {
      vt(e, r, i), n = !0;
    },
    p(r, i) {
      const s = {};
      i[0] & /*shape*/
      1 && (s.shape = /*shape*/
      r[0]), i[0] & /*transform*/
      4 && (s.transform = /*transform*/
      r[2]), i[0] & /*svgEl*/
      16 && (s.svgEl = /*svgEl*/
      r[4]), i[0] & /*midpoints, visibleMidpoint, viewportScale, isHandleHovered, geom, selectedCorners, grab, computedStyle, mask*/
      268437482 | i[1] & /*$$scope*/
      128 && (s.$$scope = { dirty: i, ctx: r }), e.$set(s);
    },
    i(r) {
      n || (Y(e.$$.fragment, r), n = !0);
    },
    o(r) {
      rt(e.$$.fragment, r), n = !1;
    },
    d(r) {
      Et(e, r);
    }
  };
}
const di = 250, yi = 1e3, ne = 4.5;
function mi(t, e, n) {
  let r, i, s;
  const u = Fe();
  let { shape: o } = e, { computedStyle: a } = e, { transform: c } = e, { viewportScale: d = 1 } = e, { svgEl: y } = e, b, L = !1, N, M = [];
  const k = () => n(7, L = !0), K = () => n(7, L = !1), O = (h) => {
    if (M.length > 0 || !i.some((R) => R.visible)) {
      n(6, b = void 0);
      return;
    }
    const [m, E] = c.elementToImage(h.offsetX, h.offsetY), S = (R) => Math.pow(R[0] - m, 2) + Math.pow(R[1] - E, 2), T = _r(r).reduce((R, F) => S(F) < S(R) ? F : R), P = i.filter((R) => R.visible).reduce((R, F) => S(F.point) < S(R.point) ? F : R), I = Math.pow(yi / d, 2);
    S(T) < I || S(P.point) < I ? n(6, b = i.indexOf(P)) : n(6, b = void 0);
  }, A = () => {
    document.activeElement !== y && y.focus();
  }, X = () => {
    n(8, M = []), A();
  }, q = (h) => {
    n(7, L = !0), h.preventDefault(), h.stopPropagation(), N = performance.now();
  }, U = (h, m, E) => (S) => {
    if (!N || Rt || performance.now() - N > di) return;
    const T = (I) => I.polygon === h && I.ring === m && I.point === E, P = M.some(T);
    S.metaKey || S.ctrlKey || S.shiftKey ? P ? n(8, M = M.filter((I) => !T(I))) : n(8, M = [...M, { polygon: h, ring: m, point: E }]) : P && M.length > 1 ? n(8, M = [{ polygon: h, ring: m, point: E }]) : P ? n(8, M = []) : n(8, M = [{ polygon: h, ring: m, point: E }]), A();
  }, C = (h, m, E) => {
    A();
    const S = h.geometry.polygons;
    let T;
    if (m === "SHAPE")
      T = S.map((P) => {
        const I = P.rings.map((F, Q) => ({ points: F.points.map((G, W) => [G[0] + E[0], G[1] + E[1]]) })), R = Lt(I[0].points);
        return { rings: I, bounds: R };
      });
    else {
      const [P, I, R, F] = m.split("-").map((Q) => parseInt(Q));
      T = S.map((Q, G) => {
        if (G === I) {
          const W = Q.rings.map((yt, Mt) => Mt === R ? { points: yt.points.map((at, ft) => ft === F ? [at[0] + E[0], at[1] + E[1]] : at) } : yt), it = Lt(W[0].points);
          return { rings: W, bounds: it };
        } else
          return Q;
      });
    }
    return {
      ...h,
      geometry: {
        polygons: T,
        bounds: Te(T)
      }
    };
  }, l = (h) => async (m) => {
    m.stopPropagation();
    const E = i[h], S = r.polygons.map((P, I) => {
      if (I === E.elementIdx) {
        const R = P.rings.map((Q, G) => G === E.ringIdx ? { points: [
          ...Q.points.slice(0, E.pointIdx + 1),
          E.point,
          ...Q.points.slice(E.pointIdx + 1)
        ] } : Q), F = Lt(R[0].points);
        return { rings: R, bounds: F };
      } else
        return P;
    });
    u("change", {
      ...o,
      geometry: {
        polygons: S,
        bounds: Te(S)
      }
    }), await Hn();
    const T = [...document.querySelectorAll(".a9s-handle")][h + 1];
    if (T != null && T.firstChild) {
      const P = new PointerEvent(
        "pointerdown",
        {
          bubbles: !0,
          cancelable: !0,
          clientX: m.clientX,
          clientY: m.clientY,
          pointerId: m.pointerId,
          pointerType: m.pointerType,
          isPrimary: m.isPrimary,
          buttons: m.buttons
        }
      );
      T.firstChild.dispatchEvent(P);
    }
  }, f = () => {
    const h = r.polygons.map((m, E) => {
      if (M.some((S) => S.polygon === E)) {
        const S = m.rings.map((P, I) => {
          const R = M.filter((F) => F.polygon === E && F.ring === I);
          return R.length && P.points.length - R.length >= 3 ? { points: P.points.filter((F, Q) => !R.some((G) => G.point === Q)) } : P;
        }), T = Lt(S[0].points);
        return { rings: S, bounds: T };
      } else
        return m;
    });
    !Ue(r.polygons, h) && (u("change", {
      ...o,
      geometry: {
        polygons: h,
        bounds: Te(h)
      }
    }), n(8, M = []));
  };
  Un(() => {
    if (Rt) return;
    const h = (m) => {
      (m.key === "Delete" || m.key === "Backspace") && (m.preventDefault(), f());
    };
    return y.addEventListener("pointermove", O), y.addEventListener("keydown", h), () => {
      y.removeEventListener("pointermove", O), y.removeEventListener("keydown", h);
    };
  });
  const g = `polygon-mask-${Math.random().toString(36).substring(2, 12)}`, $ = (h, m, E, { polygon: S, ring: T, point: P }) => S === h && T === m && P === E;
  function x(h) {
    ct.call(this, t, h);
  }
  function v(h) {
    ct.call(this, t, h);
  }
  function w(h) {
    ct.call(this, t, h);
  }
  return t.$$set = (h) => {
    "shape" in h && n(0, o = h.shape), "computedStyle" in h && n(1, a = h.computedStyle), "transform" in h && n(2, c = h.transform), "viewportScale" in h && n(3, d = h.viewportScale), "svgEl" in h && n(4, y = h.svgEl);
  }, t.$$.update = () => {
    t.$$.dirty[0] & /*shape*/
    1 && n(5, r = o.geometry), t.$$.dirty[0] & /*geom, viewportScale*/
    40 && n(10, i = Rt ? [] : hi(r, d)), t.$$.dirty[0] & /*geom, viewportScale*/
    40 && n(9, s = je(r.bounds, ne / d));
  }, [
    o,
    a,
    c,
    d,
    y,
    r,
    b,
    L,
    M,
    s,
    i,
    k,
    K,
    X,
    q,
    U,
    C,
    l,
    g,
    $,
    x,
    v,
    w
  ];
}
class xi extends Kt {
  constructor(e) {
    super(), Vt(
      this,
      e,
      mi,
      gi,
      Xt,
      {
        shape: 0,
        computedStyle: 1,
        transform: 2,
        viewportScale: 3,
        svgEl: 4
      },
      null,
      [-1, -1]
    );
  }
}
ut.RECTANGLE, ut.POLYGON, ut.MULTIPOLYGON;
typeof navigator > "u" || navigator.userAgent.indexOf("Mac OS X");
const wi = (t, e = 1e3) => {
  const { cx: n, cy: r, rx: i, ry: s } = t, u = [];
  for (let o = 0; o < e; o++) {
    const a = o / e * 2 * Math.PI, c = n + i * Math.cos(a), d = r + s * Math.sin(a);
    u.push([c, d]);
  }
  return [[...u, u[0]]];
}, vi = (t) => t.polygons.map(
  (e) => e.rings.map((n) => n.points)
), Ei = (t) => [De(t.points, t.closed)], Si = (t) => [[
  [t.x, t.y],
  [t.x + t.w, t.y],
  [t.x + t.w, t.y + t.h],
  [t.x, t.y + t.h],
  [t.x, t.y]
]], Ln = (t) => {
  const { selector: e } = t.target;
  if (e.type === ut.ELLIPSE)
    return wi(e.geometry);
  if (e.type === ut.MULTIPOLYGON)
    return vi(e.geometry);
  if (e.type === ut.POLYGON)
    return [e.geometry.points];
  if (e.type === ut.POLYLINE)
    return Ei(e.geometry);
  if (e.type === ut.RECTANGLE)
    return Si(e.geometry);
  console.warn(`[plugin-boolean-operations] Unsupported shape type: ${e.type}`);
};
function $i(t) {
  return t && t.__esModule && Object.prototype.hasOwnProperty.call(t, "default") ? t.default : t;
}
var Ae = { exports: {} }, Mn;
function bi() {
  return Mn || (Mn = 1, (function(t) {
    (function() {
      function e(o, a) {
        var c = o.x - a.x, d = o.y - a.y;
        return c * c + d * d;
      }
      function n(o, a, c) {
        var d = a.x, y = a.y, b = c.x - d, L = c.y - y;
        if (b !== 0 || L !== 0) {
          var N = ((o.x - d) * b + (o.y - y) * L) / (b * b + L * L);
          N > 1 ? (d = c.x, y = c.y) : N > 0 && (d += b * N, y += L * N);
        }
        return b = o.x - d, L = o.y - y, b * b + L * L;
      }
      function r(o, a) {
        for (var c = o[0], d = [c], y, b = 1, L = o.length; b < L; b++)
          y = o[b], e(y, c) > a && (d.push(y), c = y);
        return c !== y && d.push(y), d;
      }
      function i(o, a, c, d, y) {
        for (var b = d, L, N = a + 1; N < c; N++) {
          var M = n(o[N], o[a], o[c]);
          M > b && (L = N, b = M);
        }
        b > d && (L - a > 1 && i(o, a, L, d, y), y.push(o[L]), c - L > 1 && i(o, L, c, d, y));
      }
      function s(o, a) {
        var c = o.length - 1, d = [o[0]];
        return i(o, 0, c, a, d), d.push(o[c]), d;
      }
      function u(o, a, c) {
        if (o.length <= 2) return o;
        var d = a !== void 0 ? a * a : 1;
        return o = c ? o : r(o, d), o = s(o, d), o;
      }
      t.exports = u, t.exports.default = u;
    })();
  })(Ae)), Ae.exports;
}
var Ti = bi();
const Oi = /* @__PURE__ */ $i(Ti), Li = (t) => t.geometry.polygons.length === 1 && t.geometry.polygons[0].rings.length === 1, Mi = (t) => {
  if (Li(t)) {
    const { rings: e, bounds: n } = t.geometry.polygons[0];
    return {
      type: ut.POLYGON,
      geometry: {
        bounds: n,
        points: e[0].points
      }
    };
  } else
    return t;
}, Pi = (t, e = 0.5, n = !0) => Oi(t.map(([r, i]) => ({ x: r, y: i })), e, n).map(({ x: r, y: i }) => [r, i]), Pn = (t) => {
  const e = t.reduce((r, i) => [...r, ...i[0]], []), n = {
    type: ut.MULTIPOLYGON,
    geometry: {
      polygons: t.map((r) => ({
        // Note that polyclip-ts always duplicates the starting point–remove
        rings: r.map((i) => ({ points: Pi(i.slice(0, -1)) })),
        // Outer ring bounds           
        bounds: Lt(r[0])
      })),
      bounds: Lt(e)
    }
  };
  return Mi(n);
}, Ni = (t) => {
  const { store: e, selection: n } = t.state, r = () => n.selected.map((c) => c.id).map((c) => e.getAnnotation(c)).filter(Boolean), i = (a = { strategy: "merge_bodies" }) => {
    const c = r(), [d, ...y] = c;
    if (!d || y.length === 0) return;
    const b = c.map(Ln), [L, ...N] = b, M = pr(L, ...N), k = Pn(M);
    let K;
    "bodies" in a ? typeof a.bodies == "function" ? K = a.bodies(c) : K = [...a.bodies] : a.strategy === "keep_first_bodies" ? K = [...d.bodies] : K = [d, ...y].flatMap((A) => A.bodies);
    const O = {
      ...d,
      bodies: K,
      target: {
        ...d.target,
        selector: k
      }
    };
    e.updateAnnotation(O), e.bulkDeleteAnnotations(y);
  }, s = (a) => {
    const [c, ...d] = a, y = [c, ...d].map(Ln), [b, ...L] = y;
    return gr(b, ...L);
  };
  return {
    canSubtractSelected: () => {
      const a = r();
      return (a || []).length < 2 ? !1 : s(a).length > 0;
    },
    mergeSelected: i,
    subtractSelected: () => {
      const a = r();
      if ((a || []).length < 2) return;
      const c = s(a);
      if (c.length === 0) {
        console.warn("Cannot subtract: result empty");
        return;
      }
      const d = Pn(c), [y, ...b] = a, L = {
        ...y,
        target: {
          ...y.target,
          selector: d
        }
      };
      e.updateAnnotation(L), e.bulkDeleteAnnotations(b);
    }
  };
}, subtractAnnotations = (target, eraser) => {
  const result = gr(Ln(target), Ln(eraser));
  if (result.length === 0)
    return null;
  return {
    ...target,
    target: {
      ...target.target,
      selector: Pn(result)
    }
  };
};
export {
  Ni as mountPlugin,
  subtractAnnotations
};
//# sourceMappingURL=index.js.map
