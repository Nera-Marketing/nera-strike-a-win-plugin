/**
 * Russian's three plural forms (one/few/many) from a pipe-separated string —
 * identical to the client's own supplied `_plural_helper_js`
 * (languages/ru/02b-russian-strings-dev-build.json).
 */
export function ruPlural( n, forms ) {
	const f = forms.split( '|' );
	const m10 = n % 10;
	const m100 = n % 100;
	if ( m10 === 1 && m100 !== 11 ) {
		return f[ 0 ];
	}
	if ( m10 >= 2 && m10 <= 4 && ( m100 < 12 || m100 > 14 ) ) {
		return f[ 1 ];
	}
	return f[ 2 ];
}

/**
 * Picks the right form out of a pipe-separated plural TEMPLATE
 * (`Nera_SAW_I18n::n_template()` on the PHP side) once the actual count is
 * known — which, for several of these strings (a per-question ticket
 * reward, a running tickets total), only happens client-side as the quiz
 * runs, well after the chrome strings table is built. Two forms is English's
 * own singular/plural rule (unchanged from what `_n()` already did at these
 * call sites); three is Russian's one/few/many via `ruPlural()`. A value
 * with no `|` was never plural-packed (nothing to pick) and is returned as
 * given.
 */
export function pickPluralForm( n, template ) {
	const f = template.split( '|' );
	if ( f.length === 1 ) {
		return f[ 0 ];
	}
	if ( f.length === 2 ) {
		return 1 === n ? f[ 0 ] : f[ 1 ];
	}
	return ruPlural( n, template );
}

/**
 * Puts the noun written right after `{key}` in the form that agrees with `n`,
 * whichever of `forms` (pipe-separated: "ticket|tickets", or Russian's
 * one|few|many) the text was saved with. Any other word after the placeholder
 * is left as written; the placeholder itself stays for the caller to fill.
 */
export function agreeNoun( text, key, n, forms ) {
	const s = String( text || '' );
	if ( ! forms || s.indexOf( '{' + key + '}' ) === -1 ) {
		return s;
	}
	const alts = [ ...new Set( forms.split( '|' ) ) ]
		.sort( ( a, b ) => b.length - a.length )
		.map( ( f ) => f.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) );
	const re = new RegExp( '\\{' + key + '\\}(\\s+)(?:' + alts.join( '|' ) + ')(?!\\p{L})', 'gu' );
	return s.replace( re, ( m, gap ) => '{' + key + '}' + gap + pickPluralForm( n, forms ) );
}
