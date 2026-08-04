import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import AccountVoiceIcon from 'vue-material-design-icons/AccountVoice.vue'
import AirplaneIcon from 'vue-material-design-icons/Airplane.vue'
import BasketballIcon from 'vue-material-design-icons/Basketball.vue'
import BookOutlineIcon from 'vue-material-design-icons/BookOutline.vue'
import BusIcon from 'vue-material-design-icons/Bus.vue'
import CakeVariantIcon from 'vue-material-design-icons/CakeVariant.vue'
import CalendarBlankIcon from 'vue-material-design-icons/CalendarBlank.vue'
import CameraOutlineIcon from 'vue-material-design-icons/CameraOutline.vue'
import CarOutlineIcon from 'vue-material-design-icons/CarOutline.vue'
import CoffeeIcon from 'vue-material-design-icons/Coffee.vue'
import FlagOutlineIcon from 'vue-material-design-icons/FlagOutline.vue'
import GlassCocktailIcon from 'vue-material-design-icons/GlassCocktail.vue'
import HeartOutlineIcon from 'vue-material-design-icons/HeartOutline.vue'
import HomeGroupIcon from 'vue-material-design-icons/HomeGroup.vue'
import HomeOutlineIcon from 'vue-material-design-icons/HomeOutline.vue'
import MicrophoneIcon from 'vue-material-design-icons/Microphone.vue'
import MusicNoteIcon from 'vue-material-design-icons/MusicNote.vue'
import PaletteIcon from 'vue-material-design-icons/Palette.vue'
import PartyPopperIcon from 'vue-material-design-icons/PartyPopper.vue'
import SchoolIcon from 'vue-material-design-icons/School.vue'
import SoccerIcon from 'vue-material-design-icons/Soccer.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import TagOutlineIcon from 'vue-material-design-icons/TagOutline.vue'
import TheaterIcon from 'vue-material-design-icons/Theater.vue'
import TrophyOutlineIcon from 'vue-material-design-icons/TrophyOutline.vue'

// Mirrors CategoryService::ICONS on the server, and the icon map in the
// Flutter app — keep all three in sync.
export const DEFAULT_CATEGORY_ICON = 'tag'

export const CATEGORY_ICONS = {
	tag: TagOutlineIcon,
	'music-note': MusicNoteIcon,
	microphone: MicrophoneIcon,
	calendar: CalendarBlankIcon,
	group: AccountGroupIcon,
	star: StarOutlineIcon,
	flag: FlagOutlineIcon,
	heart: HeartOutlineIcon,
	coffee: CoffeeIcon,
	basketball: BasketballIcon,
	soccer: SoccerIcon,
	book: BookOutlineIcon,
	car: CarOutlineIcon,
	school: SchoolIcon,
	cake: CakeVariantIcon,
	trophy: TrophyOutlineIcon,
	cocktail: GlassCocktailIcon,
	home: HomeOutlineIcon,
	camera: CameraOutlineIcon,
	theater: TheaterIcon,
	airplane: AirplaneIcon,
	palette: PaletteIcon,
	bus: BusIcon,
	cabin: HomeGroupIcon,
	voice: AccountVoiceIcon,
	celebration: PartyPopperIcon,
}

export function categoryIconComponent(icon) {
	return CATEGORY_ICONS[icon] ?? CATEGORY_ICONS[DEFAULT_CATEGORY_ICON]
}
