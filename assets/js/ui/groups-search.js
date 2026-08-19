import domready from 'mf-js/modules/dom/ready';

domready( () => {
	const input = document.getElementById( 'form_groups_search_bar' );
	if(input!==null){
		input.addEventListener('input', delay(searchGroups, 500) );
	}

	// Filtre par commission : on recharge la liste sans quitter la page, et le
	// texte déjà saisi est conservé. (#23)
	const filter = document.getElementById( 'groups-filter-commission' );
	if(filter!==null){
		filter.addEventListener('change', () => {
			const url = new URL(window.location.href);

			if(filter.value){
				url.searchParams.set('commission', filter.value);
			} else {
				url.searchParams.delete('commission');
			}

			window.history.replaceState({}, '', url);

			const text = input !== null ? input.value : '';
			getGroupHTML('groups-to-activate-elements', text);
			getGroupHTML('all-groups-container', text);
		});
	}
});

async function searchGroups(e){
	getGroupHTML('groups-to-activate-elements', e.target.value);
	getGroupHTML('all-groups-container', e.target.value);
}

function currentCommission(){
	const filter = document.getElementById( 'groups-filter-commission' );

	return filter !== null && filter.value ? filter.value : '';
}

async function getGroupHTML(id, text){
	const groups = document.getElementById( id );
	if(groups!==null){
		const searchType = id;
		const newGroupsObject = await fetch(
				"/groups/search?type=" + encodeURIComponent(searchType)
				+ "&q=" + encodeURIComponent(text)
				+ "&commission=" + encodeURIComponent(currentCommission())
		).then(response => response.json());
		groups.innerHTML = newGroupsObject.groups;
	}
}

function delay(fn, ms) {
	let timer = 0;
	return function(...args) {
	  clearTimeout(timer)
	  timer = setTimeout(fn.bind(this, ...args), ms || 0)
	}
}
