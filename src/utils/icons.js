/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import {
	Home,
	Building,
	Bed,
	Bath,
	Utensils,
	Car,
	Package,
	Armchair,
	Trash2,
	BrushCleaning,
	Sparkles,
	ShoppingCart,
	Shirt,
	Wrench,
	CheckCircle,
	Circle,
	Plus,
	Minus,
	Edit,
	Trash,
	Settings,
	Menu,
	X,
	ChevronDown,
	ChevronUp,
	ChevronLeft,
	ChevronRight,
	Search,
	Filter,
	SortAsc,
	SortDesc,
	Star,
	Zap,
	Flame,
	Trophy,
	Award,
	Medal,
	Target,
	TrendingUp,
	Users,
	User,
	UserPlus,
	UserMinus,
	Calendar,
	Clock,
	Bell,
	AlertCircle,
	Info,
	HelpCircle,
	MoreHorizontal,
	MoreVertical,
	Grid,
	List,
	LayoutGrid,
	BarChart3,
	PieChart,
	Activity,
	Heart,
	ThumbsUp,
	MessageSquare,
	Share2,
	Download,
	Upload,
	ExternalLink,
	Link,
	Copy,
	RefreshCw,
	RotateCcw,
	Undo,
	Redo,
	Save,
	Printer,
	MapPin,
	Navigation,
	Locate,
	Globe,
	Shield,
	Lock,
	Unlock,
	Eye,
	EyeOff,
	Key,
	LogOut,
	LogIn,
	ArrowLeft,
	ArrowRight,
	ArrowUp,
	ArrowDown,
	Maximize,
	Minimize,
	Maximize2,
	Minimize2,
	Fullscreen,
} from 'lucide-vue-next'

/**
 * Lucide icon registry for ChoreStrike
 *
 * All icons are imported from lucide-vue-next and registered here
 * for consistent usage across the application.
 */
export const icons = {
	// Navigation & UI
	Home,
	Building,
	Menu,
	X,
	ArrowLeft,
	ArrowRight,
	ArrowUp,
	ArrowDown,
	ChevronDown,
	ChevronUp,
	ChevronLeft,
	ChevronRight,
	MoreHorizontal,
	MoreVertical,
	Search,
	Settings,
	Fullscreen,
	Maximize,
	Minimize,
	Maximize2,
	Minimize2,

	// Actions
	Plus,
	Minus,
	Edit,
	Trash,
	Trash2,
	CheckCircle,
	Circle,
	Save,
	Copy,
	RefreshCw,
	RotateCcw,
	Undo,
	Redo,
	Download,
	Upload,
	ExternalLink,
	Link,
	Share2,
	Printer,

	// Rooms
	Bed,
	Bath,
	Utensils,
	Armchair,
	Car,
	Package,

	// Tasks & Cleaning
	BrushCleaning,
	Sparkles,
	ShoppingCart,
	Shirt,
	Wrench,

	// Gamification
	Star,
	Zap,
	Flame,
	Trophy,
	Award,
	Medal,
	Target,
	TrendingUp,

	// Users
	Users,
	User,
	UserPlus,
	UserMinus,

	// Time & Scheduling
	Calendar,
	Clock,
	Bell,

	// Info & Feedback
	AlertCircle,
	Info,
	HelpCircle,
	Heart,
	ThumbsUp,
	MessageSquare,

	// Layout & Views
	Grid,
	List,
	LayoutGrid,
	Filter,
	SortAsc,
	SortDesc,

	// Stats
	BarChart3,
	PieChart,
	Activity,

	// Security
	Shield,
	Lock,
	Unlock,
	Eye,
	EyeOff,
	Key,
	LogOut,
	LogIn,

	// Location
	MapPin,
	Navigation,
	Locate,
	Globe,
}

/**
 * Get icon component by name
 *
 * @param {string} name - The icon name
 * @return {Component|undefined} The icon component or undefined if not found
 */
export function getIcon(name) {
	return icons[name]
}

/**
 * Check if an icon exists
 *
 * @param {string} name - The icon name
 * @return {boolean} True if the icon exists
 */
export function hasIcon(name) {
	return name in icons
}

export default icons
